<?php

namespace Relay\Transport;

use Relay\Config;
use Relay\RelayException;
use Relay\RequestBody;

/**
 * ترنسپورت جایگزین با stream wrapper، برای هاست‌هایی که cURL ندارند.
 *
 * محدودیت‌ها نسبت به CurlTransport:
 *  - بدنه درخواست باید در حافظه جا شود (استریم آپلود ندارد)
 *  - پین کردن IP ممکن نیست
 * بنابراین اگر cURL در دسترس است همان استفاده می‌شود.
 */
class StreamTransport implements Transport
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'stream';
    }

    public static function isAvailable(): bool
    {
        return (bool) ini_get('allow_url_fopen');
    }

    public function send(OutgoingRequest $request, callable $onHeaders, callable $onChunk): TransportResult
    {
        $result            = new TransportResult();
        $result->transport = $this->name();

        $headers = $request->headers;
        $content = $this->resolveContent($request, $headers);

        $httpOptions = [
            'method'           => $request->method,
            'header'           => implode("\r\n", $headers),
            'follow_location'  => 0,
            'max_redirects'    => 1,
            'ignore_errors'    => true,
            'timeout'          => max(1, $this->config->int('http.timeout', 120)),
            'protocol_version' => 1.1,
        ];

        if ($content !== null) {
            $httpOptions['content'] = $content;
        }

        $sslOptions = [
            'verify_peer'      => $this->config->bool('http.verify_tls', true),
            'verify_peer_name' => $this->config->bool('http.verify_tls', true),
            'SNI_enabled'      => true,
        ];

        $caBundle = $this->config->get('http.ca_bundle');

        if (is_string($caBundle) && $caBundle !== '' && is_readable($caBundle)) {
            $sslOptions['cafile'] = $caBundle;
        }

        $context = stream_context_create(['http' => $httpOptions, 'ssl' => $sslOptions]);

        $startedAt = microtime(true);

        set_error_handler(static function () {
            return true;
        });

        $handle = fopen($request->url(), 'rb', false, $context);

        restore_error_handler();

        if ($handle === false) {
            $result->duration = microtime(true) - $startedAt;
            $result->error    = 'اتصال به مقصد برقرار نشد (stream wrapper).';

            return $result;
        }

        $meta        = stream_get_meta_data($handle);
        $rawHeaders  = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];
        $parsed      = $this->parseHeaders($rawHeaders);

        $result->status  = $parsed['status'];
        $result->headers = $parsed['headers'];

        if ($request->followRedirect && in_array($result->status, [301, 302, 303, 307, 308], true)) {
            $location = $result->header('Location');

            if ($location !== null && trim($location) !== '') {
                $result->redirectLocation = trim($location);
                $result->abortReason      = 'redirect';
                $result->duration         = microtime(true) - $startedAt;

                fclose($handle);

                return $result;
            }
        }

        $maxResponse = max(0, $this->config->int('http.max_response_body', 0));
        $declared    = $result->header('Content-Length');

        if ($maxResponse > 0 && $declared !== null && (int) $declared > $maxResponse) {
            fclose($handle);

            throw new RelayException(
                502,
                'response_too_large',
                'حجم پاسخ مقصد از حد مجاز بیشتر است.'
            );
        }

        $result->headersDelivered = true;
        $onHeaders($result->status, $result->headers);

        if ($request->expectsResponseBody()) {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $result->bytes += strlen($chunk);

                if ($maxResponse > 0 && $result->bytes > $maxResponse) {
                    fclose($handle);

                    throw new RelayException(
                        502,
                        'response_too_large',
                        'حجم پاسخ مقصد از حد مجاز بیشتر است.'
                    );
                }

                $onChunk($chunk);
            }
        }

        fclose($handle);

        $result->duration = microtime(true) - $startedAt;

        return $result;
    }

    /**
     * بدنه را به رشته تبدیل می‌کند (این ترنسپورت استریم آپلود ندارد).
     *
     * @param array<int,string> $headers
     */
    private function resolveContent(OutgoingRequest $request, array &$headers): ?string
    {
        $body = $request->body;

        switch ($body->mode()) {
            case RequestBody::MODE_STRING:
                return $body->content();

            case RequestBody::MODE_MULTIPART:
                [$content, $contentType] = MultipartBuilder::build($body->fields());
                $headers = $this->replaceHeader($headers, 'Content-Type', $contentType);

                return $content;

            case RequestBody::MODE_STREAM:
                $stream = $body->stream();

                if (!is_resource($stream)) {
                    return null;
                }

                $content = stream_get_contents($stream);

                return $content === false ? null : $content;

            default:
                return null;
        }
    }

    /**
     * @param array<int,string> $headers
     * @return array<int,string>
     */
    private function replaceHeader(array $headers, string $name, string $value): array
    {
        $filtered = [];

        foreach ($headers as $header) {
            if (stripos($header, $name . ':') !== 0) {
                $filtered[] = $header;
            }
        }

        $filtered[] = $name . ': ' . $value;

        return $filtered;
    }

    /**
     * @param array<int,string> $rawHeaders
     * @return array{status:int,headers:array<int,array{0:string,1:string}>}
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $status  = 0;
        $headers = [];

        foreach ($rawHeaders as $line) {
            $line = trim((string) $line);

            if (stripos($line, 'HTTP/') === 0) {
                // در صورت ریدایرکت داخلی، آخرین خط وضعیت معتبر است
                $parts   = explode(' ', $line, 3);
                $status  = isset($parts[1]) ? (int) $parts[1] : 0;
                $headers = [];

                continue;
            }

            $colon = strpos($line, ':');

            if ($colon !== false) {
                $headers[] = [substr($line, 0, $colon), ltrim(substr($line, $colon + 1))];
            }
        }

        return ['status' => $status, 'headers' => $headers];
    }
}
