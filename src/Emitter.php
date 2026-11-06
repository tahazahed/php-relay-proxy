<?php

namespace Relay;

/**
 * ارسال پاسخ به کلاینت.
 *
 * برای اینکه دانلود فایل حجیم حافظه را پر نکند، هر chunk بلافاصله
 * flush می‌شود و هیچ output buffering فعالی باقی نمی‌ماند.
 */
class Emitter
{
    /** @var bool */
    private $started = false;

    /** @var int */
    private $bytesSent = 0;

    /**
     * آماده‌سازی محیط PHP برای پاسخ استریم.
     */
    public static function prepare(): void
    {
        // فشرده‌سازی خودکار PHP، Content-Length مقصد را بی‌اعتبار می‌کند
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    public function started(): bool
    {
        return $this->started;
    }

    public function bytesSent(): int
    {
        return $this->bytesSent;
    }

    /**
     * ارسال خط وضعیت و هدرها.
     *
     * @param array<int,array{0:string,1:string}> $headers
     */
    public function sendHeaders(int $status, array $headers): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        if (headers_sent()) {
            return;
        }

        http_response_code($status > 0 ? $status : 502);

        $seen = [];

        foreach ($headers as $header) {
            $name  = $header[0];
            $lower = strtolower($name);

            // Set-Cookie و مشابه می‌توانند چند بار بیایند
            $replace = !isset($seen[$lower]) && !$this->isMultiValue($lower);
            $seen[$lower] = true;

            header($name . ': ' . $header[1], $replace);
        }
    }

    public function write(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->bytesSent += strlen($chunk);

        echo $chunk;

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }

    /**
     * پاسخ JSON (برای خطاها و پاسخ‌های داخلی رله).
     *
     * @param array<string,mixed>                 $payload
     * @param array<int,array{0:string,1:string}> $extraHeaders
     */
    public function json(int $status, array $payload, array $extraHeaders = []): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($body === false) {
            $body = '{"ok":false,"error":"encoding_failed"}';
        }

        $headers = array_merge(
            [
                ['Content-Type', 'application/json; charset=utf-8'],
                ['Content-Length', (string) strlen($body)],
                ['Cache-Control', 'no-store'],
            ],
            $extraHeaders
        );

        $this->sendHeaders($status, $headers);
        $this->write($body);
    }

    private function isMultiValue(string $lower): bool
    {
        return in_array($lower, ['set-cookie', 'www-authenticate', 'proxy-authenticate', 'link', 'vary'], true);
    }
}
