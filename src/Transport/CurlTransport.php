<?php

namespace Relay\Transport;

use Relay\Config;
use Relay\RelayException;
use Relay\RequestBody;

/**
 * ترنسپورت اصلی مبتنی بر cURL.
 *
 * ویژگی‌ها:
 *  - استریم پاسخ (بدون بارگذاری کامل در حافظه) برای دانلود فایل حجیم
 *  - استریم آپلود برای بدنه‌های بزرگ
 *  - ریدایرکت دستی: هر hop دوباره اعتبارسنجی امنیتی می‌شود
 *  - پین کردن IP برای جلوگیری از DNS rebinding
 */
class CurlTransport implements Transport
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'curl';
    }

    public static function isAvailable(): bool
    {
        return function_exists('curl_init') && function_exists('curl_setopt');
    }

    public function send(OutgoingRequest $request, callable $onHeaders, callable $onChunk): TransportResult
    {
        $result            = new TransportResult();
        $result->transport = $this->name();

        $handle = curl_init();

        if ($handle === false) {
            throw new RelayException(500, 'curl_init_failed', 'راه‌اندازی cURL ناموفق بود.');
        }

        // ── وضعیت میانی که کال‌بک‌ها به اشتراک می‌گذارند ────────────────
        $state = [
            'headers'      => [],
            'status'       => 0,
            'finalized'    => false,
            'maxResponse'  => max(0, $this->config->int('http.max_response_body', 0)),
        ];

        $options = [
            CURLOPT_URL            => $request->url(),
            CURLOPT_CUSTOMREQUEST  => $request->method,
            CURLOPT_HTTPHEADER     => array_merge($request->headers, ['Expect:']),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, $this->config->int('http.connect_timeout', 10)),
            CURLOPT_TIMEOUT        => max(0, $this->config->int('http.timeout', 120)),
            CURLOPT_SSL_VERIFYPEER => $this->config->bool('http.verify_tls', true),
            CURLOPT_SSL_VERIFYHOST => $this->config->bool('http.verify_tls', true) ? 2 : 0,
        ];

        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        $caBundle = $this->config->get('http.ca_bundle');

        if (is_string($caBundle) && $caBundle !== '' && is_readable($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        $lowSpeedLimit = $this->config->int('http.low_speed_limit', 0);

        if ($lowSpeedLimit > 0) {
            $options[CURLOPT_LOW_SPEED_LIMIT] = $lowSpeedLimit;
            $options[CURLOPT_LOW_SPEED_TIME]  = max(1, $this->config->int('http.low_speed_time', 30));
        }

        switch ($this->config->int('http.force_ip_version', 0)) {
            case 4:
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
                break;
            case 6:
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
                break;
        }

        $httpVersion = (string) $this->config->get('http.http_version', 0);

        if ($httpVersion === '1.1') {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        } elseif ($httpVersion === '2.0' && defined('CURL_HTTP_VERSION_2_0')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
        }

        // پین کردن IP تا بین اعتبارسنجی و اتصال، DNS عوض نشود
        $resolve = $this->buildResolveEntry($request);

        if ($resolve !== null) {
            $options[CURLOPT_RESOLVE] = [$resolve];
        }

        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        $this->attachBody($options, $request);

        // ── کال‌بک هدر ─────────────────────────────────────────────────
        $options[CURLOPT_HEADERFUNCTION] = function ($ch, string $line) use (&$state, &$result, $request, $onHeaders) {
            $length  = strlen($line);
            $trimmed = trim($line);

            // خط وضعیت — شروع یک بلوک هدر جدید
            if (stripos($trimmed, 'HTTP/') === 0) {
                $state['headers'] = [];
                $parts            = explode(' ', $trimmed, 3);
                $state['status']  = isset($parts[1]) ? (int) $parts[1] : 0;

                return $length;
            }

            // پایان بلوک هدر
            if ($trimmed === '') {
                // پاسخ‌های اطلاعاتی (1xx) پاسخ نهایی نیستند
                if ($state['status'] >= 100 && $state['status'] < 200) {
                    $state['headers'] = [];

                    return $length;
                }

                if ($state['finalized']) {
                    return $length;
                }

                $state['finalized'] = true;
                $result->status     = $state['status'];
                $result->headers    = $state['headers'];

                // اگر مقصد خودش طول بدنه را اعلام کرده و از حد بیشتر است،
                // قبل از فرستادن هدرها رد می‌کنیم تا خطای تمیز برگردد.
                $declared = $result->header('Content-Length');

                if ($state['maxResponse'] > 0 && $declared !== null && (int) $declared > $state['maxResponse']) {
                    $result->abortReason = 'too_large';

                    return 0;
                }

                // ریدایرکت: قطع کن تا فراخوان دوباره اعتبارسنجی کند
                if ($request->followRedirect && $this->isRedirectStatus($state['status'])) {
                    $location = $result->header('Location');

                    if ($location !== null && trim($location) !== '') {
                        $result->redirectLocation = trim($location);
                        $result->abortReason      = 'redirect';

                        return 0; // اجبار به قطع انتقال
                    }
                }

                $result->headersDelivered = true;
                $onHeaders($result->status, $result->headers);

                return $length;
            }

            $colon = strpos($trimmed, ':');

            if ($colon !== false) {
                $state['headers'][] = [
                    substr($trimmed, 0, $colon),
                    ltrim(substr($trimmed, $colon + 1)),
                ];
            }

            return $length;
        };

        // ── کال‌بک بدنه ────────────────────────────────────────────────
        $options[CURLOPT_WRITEFUNCTION] = function ($ch, string $chunk) use (&$state, &$result, $onChunk) {
            $length = strlen($chunk);

            if ($state['maxResponse'] > 0 && ($result->bytes + $length) > $state['maxResponse']) {
                $result->abortReason = 'too_large';

                return 0;
            }

            $result->bytes += $length;

            $onChunk($chunk);

            return $length;
        };

        curl_setopt_array($handle, $options);

        $startedAt = microtime(true);
        $ok        = curl_exec($handle);
        $result->duration = microtime(true) - $startedAt;

        $result->errno = curl_errno($handle);

        if ($ok === false && $result->errno !== 0 && $result->abortReason === null) {
            $result->error = curl_error($handle) ?: ('خطای cURL شماره ' . $result->errno);
        }

        if ($result->status === 0 && $state['status'] > 0) {
            $result->status = $state['status'];
        }

        curl_close($handle);

        if ($result->abortReason === 'too_large') {
            throw new RelayException(
                502,
                'response_too_large',
                'حجم پاسخ مقصد از حد مجاز بیشتر است.'
            );
        }

        return $result;
    }

    /**
     * ورودی CURLOPT_RESOLVE به شکل host:port:ip
     */
    private function buildResolveEntry(OutgoingRequest $request): ?string
    {
        if (!$this->config->bool('target.pin_resolved_ip', true)) {
            return null;
        }

        $target = $request->target;

        if ($target->ips === []) {
            return null;
        }

        // اگر خود هاست یک IP بوده، پین لازم نیست
        if (filter_var($target->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $version = curl_version();
        $number  = is_array($version) && isset($version['version_number'])
            ? (int) $version['version_number']
            : 0;

        // لیست چند IP از curl 7.59.0 (0x073b00) پشتیبانی می‌شود
        $ips = $number >= 0x073B00
            ? implode(',', array_slice($target->ips, 0, 5))
            : $target->ips[0];

        return $target->host . ':' . $target->port . ':' . $ips;
    }

    /**
     * بدنه درخواست را به گزینه‌های curl وصل می‌کند.
     *
     * @param array<int,mixed> $options
     */
    private function attachBody(array &$options, OutgoingRequest $request): void
    {
        $body = $request->body;

        switch ($body->mode()) {
            case RequestBody::MODE_STRING:
                $options[CURLOPT_POSTFIELDS] = $body->content();
                break;

            case RequestBody::MODE_MULTIPART:
                // curl خودش boundary و Content-Type را می‌سازد
                $options[CURLOPT_POSTFIELDS] = $body->fields();
                break;

            case RequestBody::MODE_STREAM:
                $stream = $body->stream();

                if (!is_resource($stream)) {
                    break;
                }

                $options[CURLOPT_UPLOAD]       = true;
                $options[CURLOPT_READFUNCTION] = static function ($ch, $fd, int $length) use ($stream) {
                    $data = fread($stream, $length);

                    return $data === false ? '' : $data;
                };

                $size = $body->size();

                if ($size !== null) {
                    if (defined('CURLOPT_INFILESIZE_LARGE')) {
                        $options[CURLOPT_INFILESIZE_LARGE] = $size;
                    } else {
                        $options[CURLOPT_INFILESIZE] = $size;
                    }
                }
                break;

            case RequestBody::MODE_NONE:
            default:
                // متدهایی که معمولاً بدنه دارند به Content-Length: 0 نیاز دارند؛
                // با بدنه خالی، خود curl این هدر را درست تولید می‌کند.
                if (in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
                    $options[CURLOPT_POSTFIELDS] = '';
                }
                break;
        }
    }

    private function isRedirectStatus(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }
}
