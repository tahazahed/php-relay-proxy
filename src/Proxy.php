<?php

namespace Relay;

use Relay\Transport\CurlTransport;
use Relay\Transport\OutgoingRequest;
use Relay\Transport\StreamTransport;
use Relay\Transport\Transport;
use Relay\Transport\TransportResult;

/**
 * هسته رله: درخواست ورودی را می‌گیرد، اعتبارسنجی می‌کند،
 * به مقصد می‌فرستد و پاسخ را برمی‌گرداند.
 */
class Proxy
{
    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    /** @var Emitter */
    private $emitter;

    /** @var IncomingRequest */
    private $request;

    /** @var TargetResolver */
    private $resolver;

    /** @var Headers */
    private $headers;

    /** @var Auth */
    private $auth;

    /** @var RateLimiter */
    private $rateLimiter;

    /** @var string */
    private $keyLabel = '-';

    /** @var Target|null مقصد فعلی (برای تبدیل Location نسبی به کامل) */
    private $currentTarget = null;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config      = $config;
        $this->logger      = $logger;
        $this->emitter     = new Emitter();
        $this->request     = IncomingRequest::capture();
        $this->resolver    = new TargetResolver($config);
        $this->headers     = new Headers($config);
        $this->auth        = new Auth($config);
        $this->rateLimiter = new RateLimiter($config);
    }

    public function handle(): void
    {
        $body = null;

        try {
            // ── پاسخ به preflight مرورگر، بدون تماس با مقصد ──────────
            if ($this->request->method() === 'OPTIONS' && $this->config->bool('cors.enabled', false)) {
                $this->emitter->sendHeaders(204, array_merge(
                    $this->headers->cors($this->request->header('origin')),
                    [['Content-Length', '0']]
                ));

                return;
            }

            // ── صفحه راهنما وقتی مسیر خالی است ──────────────────────
            if ($this->isRootRequest()) {
                $this->sendInfo();

                return;
            }

            // 1) استخراج مقصد (بدون تماس شبکه)
            $rawTarget = $this->resolver->extract($this->request);

            // 2) احراز هویت — قبل از هر عملیات شبکه‌ای مثل DNS
            $this->keyLabel = $this->auth->authenticate(
                $this->request,
                $this->resolver->keyFromPath(),
                $this->resolver->clientTarget()
            );

            // 3) محدودیت نرخ
            $this->rateLimiter->hit($this->keyLabel, $this->request->clientIp());

            // 4) اعتبارسنجی مقصد (شامل DNS و بررسی شبکه داخلی)
            $target = $this->resolver->validate($rawTarget);

            // 5) بدنه و هدرها
            $body    = RequestBody::capture($this->request, $this->config, $this->logger);
            $headers = $this->headers->forRequest($this->request, $body, $target);

            $this->logger->info('درخواست رله شد', [
                'request_id' => $this->request->id(),
                'key'        => $this->keyLabel,
                'ip'         => $this->request->clientIp(),
                'method'     => $this->request->method(),
                'target'     => $target->url,
                'body'       => $body->describe(),
            ]);

            // 6) ارسال با دنبال کردن دستی ریدایرکت
            $result = $this->dispatch($target, $headers, $body);

            $this->logger->info('پاسخ مقصد', [
                'request_id' => $this->request->id(),
                'status'     => $result->status,
                'bytes'      => $result->bytes,
                'duration'   => round($result->duration, 3),
                'transport'  => $result->transport,
                'error'      => $result->error,
            ]);

            if ($result->failed() && !$result->headersDelivered) {
                throw new RelayException(
                    502,
                    'upstream_error',
                    'ارتباط با مقصد برقرار نشد: ' . $result->error,
                    ['errno' => $result->errno]
                );
            }
        } catch (RelayException $exception) {
            $this->fail($exception->status(), $exception->errorCode(), $exception->getMessage(), $exception->context());
        } catch (\Throwable $exception) {
            $this->fail(
                500,
                'internal_error',
                $this->config->bool('app.debug') ? $exception->getMessage() : 'خطای داخلی رله.',
                [
                    'exception' => get_class($exception),
                    'message'   => $exception->getMessage(),
                    'file'      => $exception->getFile() . ':' . $exception->getLine(),
                ]
            );
        } finally {
            if ($body instanceof RequestBody) {
                $body->close();
            }
        }
    }

    /**
     * ارسال درخواست و دنبال کردن ریدایرکت‌ها با اعتبارسنجی هر hop.
     */
    private function dispatch(Target $target, array $headers, RequestBody $body): TransportResult
    {
        $transport    = $this->makeTransport();
        $maxRedirects = max(0, $this->config->int('http.max_redirects', 3));
        $method       = $this->request->method();
        $hops         = 0;

        $rewriter = new BodyRewriter($this->config, $this->relayBase());

        while (true) {
            $this->currentTarget      = $target;
            $outgoing                 = new OutgoingRequest($method, $target, $headers, $body);
            $outgoing->followRedirect = $hops < $maxRedirects;

            // ── حالت بافر یا استریم بر اساس نیاز به بازنویسی ──────────
            $buffer          = '';
            $shouldRewrite   = false;
            $pendingHeaders  = null;
            $pendingStatus   = 0;

            $onHeaders = function (int $status, array $responseHeaders) use (
                &$buffer, &$shouldRewrite, &$pendingHeaders, &$pendingStatus, $rewriter
            ) {
                $contentType   = '';
                $contentLength = null;

                foreach ($responseHeaders as $header) {
                    $lower = strtolower($header[0]);

                    if ($lower === 'content-type') {
                        $contentType = $header[1];
                    } elseif ($lower === 'content-length') {
                        $contentLength = (int) $header[1];
                    }
                }

                $shouldRewrite = $rewriter->appliesTo($contentType, $contentLength);

                if ($shouldRewrite) {
                    // هدرها بعد از بازنویسی فرستاده می‌شوند (طول عوض می‌شود)
                    $pendingStatus  = $status;
                    $pendingHeaders = $responseHeaders;

                    return;
                }

                $this->emitter->sendHeaders($status, $this->finalResponseHeaders($responseHeaders, false));
            };

            $onChunk = function (string $chunk) use (&$buffer, &$shouldRewrite) {
                if ($shouldRewrite) {
                    $buffer .= $chunk;

                    return;
                }

                $this->emitter->write($chunk);
            };

            $result = $transport->send($outgoing, $onHeaders, $onChunk);

            // ── ریدایرکت ─────────────────────────────────────────────
            if ($result->isRedirect()) {
                $nextUrl = $this->absolutize((string) $result->redirectLocation, $target);

                // 307/308 بدنه را نگه می‌دارند؛ اگر بدنه استریم بوده دیگر
                // قابل ارسال مجدد نیست، پس ریدایرکت را به کلاینت می‌سپاریم.
                $keepsBody = in_array($result->status, [307, 308], true);

                if ($keepsBody && $body->mode() === RequestBody::MODE_STREAM) {
                    $this->logger->warning('ریدایرکت 307/308 با بدنه استریم دنبال نشد.', [
                        'request_id' => $this->request->id(),
                        'location'   => $nextUrl,
                    ]);

                    $this->emitter->sendHeaders(
                        $result->status,
                        $this->finalResponseHeaders($result->headers, false)
                    );

                    return $result;
                }

                if (!$keepsBody && !in_array($method, ['GET', 'HEAD'], true)) {
                    // مطابق RFC: 301/302/303 متد را به GET تبدیل می‌کنند
                    $method  = 'GET';
                    $body    = RequestBody::capture(
                        $this->emptyRequestClone(),
                        $this->config,
                        $this->logger
                    );
                    $headers = $this->stripBodyHeaders($headers);
                }

                $target = $this->resolver->validate($nextUrl, true);
                $hops++;

                $this->logger->debug('ریدایرکت دنبال شد', [
                    'request_id' => $this->request->id(),
                    'hop'        => $hops,
                    'target'     => $target->url,
                ]);

                continue;
            }

            // ── بازنویسی بدنه بافرشده ────────────────────────────────
            if ($shouldRewrite && $pendingHeaders !== null) {
                $rewritten = $rewriter->apply($buffer);
                $changed   = $rewritten !== $buffer;

                $finalHeaders = $this->finalResponseHeaders($pendingHeaders, $changed);

                if ($changed) {
                    $finalHeaders[] = ['Content-Length', (string) strlen($rewritten)];
                }

                $this->emitter->sendHeaders($pendingStatus, $finalHeaders);
                $this->emitter->write($rewritten);
            }

            return $result;
        }
    }

    /**
     * هدرهای نهایی پاسخ: فیلتر شده + هدرهای خود رله.
     *
     * @param array<int,array{0:string,1:string}> $responseHeaders
     * @return array<int,array{0:string,1:string}>
     */
    private function finalResponseHeaders(array $responseHeaders, bool $bodyWasRewritten): array
    {
        $headers = $this->headers->forResponse($responseHeaders, $bodyWasRewritten);

        if ($this->config->bool('http.rewrite_location', true)) {
            $headers = $this->rewriteLocationHeader($headers);
        }

        $requestIdHeader = $this->config->str('app.request_id_header', 'X-Relay-Request-Id');

        if ($requestIdHeader !== '') {
            $headers[] = [$requestIdHeader, $this->request->id()];
        }

        if ($this->config->bool('rate_limit.enabled', true)) {
            $headers[] = ['X-Relay-RateLimit-Limit', (string) $this->rateLimiter->limit()];
            $headers[] = ['X-Relay-RateLimit-Remaining', (string) $this->rateLimiter->remaining()];
            $headers[] = ['X-Relay-RateLimit-Reset', (string) $this->rateLimiter->resetAt()];
        }

        return array_merge($headers, $this->headers->cors($this->request->header('origin')));
    }

    /**
     * هدر Location را از مسیر رله عبور می‌دهد تا کلاینت مستقیم به
     * مقصد فیلترشده وصل نشود.
     *
     * @param array<int,array{0:string,1:string}> $headers
     * @return array<int,array{0:string,1:string}>
     */
    private function rewriteLocationHeader(array $headers): array
    {
        foreach ($headers as $index => $header) {
            if (strcasecmp($header[0], 'Location') !== 0) {
                continue;
            }

            $value = trim($header[1]);

            if ($value === '') {
                continue;
            }

            // Location نسبی هم باید کامل شود، وگرنه کلاینت آن را نسبت به
            // دامنه رله حل می‌کند و به مسیر بی‌معنی می‌رسد.
            if ($this->currentTarget !== null) {
                $value = $this->absolutize($value, $this->currentTarget);
            } elseif (stripos($value, 'http') !== 0) {
                continue;
            }

            $headers[$index] = ['Location', $this->relayBase() . '/' . $value];
        }

        return $headers;
    }

    /**
     * آدرس پایه رله برای ساخت لینک‌های بازگشتی.
     * مثال: https://relay.example.com/relay/r/MYKEY
     */
    private function relayBase(): string
    {
        $prefix = $this->resolver->matchedPrefix();

        return Util::baseUrl()
            . $this->request->mount()
            . ($prefix === '' ? '' : '/' . $prefix);
    }

    /**
     * تبدیل Location نسبی به آدرس کامل.
     */
    private function absolutize(string $location, Target $current): string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $location)) {
            return $location;
        }

        if (strpos($location, '//') === 0) {
            return $current->scheme . ':' . $location;
        }

        if (strpos($location, '/') === 0) {
            return $current->origin() . $location;
        }

        $path = (string) (parse_url($current->url, PHP_URL_PATH) ?: '/');
        $base = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $current->origin() . ($base === '' ? '/' : $base) . $location;
    }

    /**
     * هدرهای مربوط به بدنه را حذف می‌کند (وقتی ریدایرکت متد را GET می‌کند).
     *
     * @param array<int,string> $headers
     * @return array<int,string>
     */
    private function stripBodyHeaders(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            static function (string $header): bool {
                $lower = strtolower($header);

                return strpos($lower, 'content-type:') !== 0
                    && strpos($lower, 'content-length:') !== 0;
            }
        ));
    }

    /**
     * یک درخواست بدون بدنه، برای ساخت RequestBody خالی بعد از ریدایرکت.
     */
    private function emptyRequestClone(): IncomingRequest
    {
        // یک درخواست GET ساده بدون بدنه می‌سازیم
        $backup                     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD']  = 'GET';
        $_SERVER['CONTENT_LENGTH']  = '0';

        $clone = IncomingRequest::capture();

        $_SERVER['REQUEST_METHOD'] = $backup;

        return $clone;
    }

    private function makeTransport(): Transport
    {
        if (CurlTransport::isAvailable()) {
            return new CurlTransport($this->config);
        }

        if (StreamTransport::isAvailable()) {
            $this->logger->warning('cURL در دسترس نیست؛ از stream wrapper استفاده شد.');

            return new StreamTransport($this->config);
        }

        throw new RelayException(
            500,
            'no_transport',
            'نه cURL فعال است و نه allow_url_fopen. از هاستینگ بخواهید یکی را فعال کند.'
        );
    }

    private function isRootRequest(): bool
    {
        $path = trim($this->request->path(), '/');

        if ($path !== '') {
            return false;
        }

        $queryParam  = $this->config->str('target.query_param', 'relay_url');
        $headerName  = $this->config->str('target.header', 'X-Relay-Target');

        return ($queryParam === '' || $this->request->query($queryParam) === '')
            && ($headerName === '' || !$this->request->hasHeader($headerName));
    }

    /**
     * پاسخ راهنما در ریشه.
     */
    private function sendInfo(): void
    {
        $base = Util::baseUrl() . $this->request->mount();

        $this->emitter->json(200, [
            'ok'      => true,
            'service' => 'php-relay',
            'usage'   => [
                'path_with_key' => $base . '/r/<KEY>/https://api.telegram.org/bot<TOKEN>/getMe',
                'path'          => $base . '/r/https://api.telegram.org/bot<TOKEN>/getMe',
                'query'         => $base . '/?relay_url=' . rawurlencode('https://api.telegram.org/bot<TOKEN>/getMe'),
                'header'        => 'X-Relay-Target: https://api.telegram.org/bot<TOKEN>/getMe',
            ],
            'auth' => [
                'header' => $this->config->str('auth.header', 'X-Relay-Key'),
                'query'  => $this->config->str('auth.query_param', 'relay_key'),
                'path'   => $this->config->bool('auth.allow_key_in_path', true),
            ],
            'time' => gmdate('c'),
        ]);
    }

    /**
     * پاسخ خطا به کلاینت + لاگ.
     *
     * @param array<string,mixed> $context
     */
    private function fail(int $status, string $code, string $message, array $context = []): void
    {
        $this->logger->log(
            $status >= 500 ? 'error' : 'warning',
            'درخواست رد شد: ' . $message,
            array_merge($context, [
                'request_id' => $this->request->id(),
                'code'       => $code,
                'status'     => $status,
                'ip'         => $this->request->clientIp(),
                'key'        => $this->keyLabel,
                'method'     => $this->request->method(),
                'path'       => $this->request->path(),
            ])
        );

        // اگر هدرها رفته‌اند، دیگر نمی‌توان وضعیت را عوض کرد
        if ($this->emitter->started()) {
            return;
        }

        $extraHeaders = $this->headers->cors($this->request->header('origin'));

        if ($status === 429 && isset($context['retry_after'])) {
            $extraHeaders[] = ['Retry-After', (string) $context['retry_after']];
        }

        $requestIdHeader = $this->config->str('app.request_id_header', 'X-Relay-Request-Id');

        if ($requestIdHeader !== '') {
            $extraHeaders[] = [$requestIdHeader, $this->request->id()];
        }

        $payload = [
            'ok'         => false,
            'error'      => $code,
            'message'    => $message,
            'request_id' => $this->request->id(),
        ];

        if ($this->config->bool('app.debug') && $context !== []) {
            $payload['debug'] = $context;
        }

        $this->emitter->json($status, $payload, $extraHeaders);
    }
}
