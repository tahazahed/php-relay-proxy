<?php

namespace Relay;

/**
 * آماده‌سازی هدرهای رفت و برگشت.
 */
class Headers
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * هدرهایی که به مقصد فرستاده می‌شوند.
     *
     * @return array<int,string> به شکل "Name: value"
     */
    public function forRequest(IncomingRequest $request, RequestBody $body, Target $target): array
    {
        $blocked = array_map(
            'strtolower',
            array_map('strval', $this->config->arr('http.request_header_blocklist'))
        );

        // هدرهای مربوط به خود رله همیشه حذف می‌شوند، حتی اگر کاربر
        // blocklist را دست بزند.
        $always = array_filter(array_map('strtolower', [
            $this->config->str('auth.header', 'X-Relay-Key'),
            $this->config->str('target.header', 'X-Relay-Target'),
            $this->config->str('auth.hmac.ts_header', 'X-Relay-Timestamp'),
            $this->config->str('auth.hmac.sig_header', 'X-Relay-Signature'),
            'host',
            'content-length',
            'connection',
            'transfer-encoding',
            'expect',
        ]));

        $blocked = array_unique(array_merge($blocked, $always));

        $headers = [];

        foreach ($request->headers() as $name => $value) {
            $lower = strtolower($name);

            if (in_array($lower, $blocked, true)) {
                continue;
            }

            // وقتی بدنه را بازسازی کرده‌ایم، boundary قبلی بی‌اعتبار است
            if ($lower === 'content-type' && $body->overridesContentType()) {
                continue;
            }

            if ($lower === 'user-agent' && !$this->config->bool('http.user_agent_passthrough', true)) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $headers[$lower] = $this->headerName($name) . ': ' . $this->sanitizeValue($value);
        }

        if (!isset($headers['user-agent'])) {
            $userAgent = $this->config->str('http.default_user_agent', 'RelayProxy/1.0');

            if ($userAgent !== '') {
                $headers['user-agent'] = 'User-Agent: ' . $userAgent;
            }
        }

        if ($this->config->bool('http.send_forwarded_headers', false)) {
            $headers['x-forwarded-for']   = 'X-Forwarded-For: ' . $request->clientIp();
            $headers['x-forwarded-proto'] = 'X-Forwarded-Proto: ' . ($this->isHttps() ? 'https' : 'http');
            $headers['x-forwarded-host']  = 'X-Forwarded-Host: ' . (string) ($_SERVER['HTTP_HOST'] ?? '');
        }

        // هدرهای ثابت تنظیمات — بر هدرهای کلاینت اولویت دارند
        foreach ($this->config->arr('http.extra_request_headers') as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $headers[strtolower($name)] = $this->headerName($name) . ': ' . $this->sanitizeValue((string) $value);
        }

        return array_values($headers);
    }

    /**
     * فیلتر هدرهای پاسخ مقصد.
     *
     * @param array<int,array{0:string,1:string}> $responseHeaders
     * @return array<int,array{0:string,1:string}>
     */
    public function forResponse(array $responseHeaders, bool $bodyWasRewritten): array
    {
        $blocked = array_map(
            'strtolower',
            array_map('strval', $this->config->arr('http.response_header_blocklist'))
        );

        // این‌ها هرگز نباید عبور کنند چون وب‌سرور خودش مدیریت می‌کند
        $blocked = array_unique(array_merge($blocked, [
            'connection',
            'keep-alive',
            'transfer-encoding',
            'upgrade',
            'proxy-authenticate',
            'proxy-authorization',
            'te',
            'trailer',
        ]));

        $filtered = [];

        foreach ($responseHeaders as $header) {
            $lower = strtolower($header[0]);

            if (in_array($lower, $blocked, true)) {
                continue;
            }

            // بازنویسی بدنه طول آن را عوض می‌کند
            if ($lower === 'content-length' && $bodyWasRewritten) {
                continue;
            }

            $filtered[] = [$this->headerName($header[0]), $this->sanitizeValue($header[1])];
        }

        return $filtered;
    }

    /**
     * هدرهای CORS برای پاسخ.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public function cors(string $origin): array
    {
        if (!$this->config->bool('cors.enabled', false)) {
            return [];
        }

        $allowed = array_map('strval', $this->config->arr('cors.allow_origins', ['*']));
        $value   = null;

        if (in_array('*', $allowed, true)) {
            $value = $this->config->bool('cors.credentials', false) && $origin !== '' ? $origin : '*';
        } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
            $value = $origin;
        }

        if ($value === null) {
            return [];
        }

        $headers = [
            ['Access-Control-Allow-Origin', $value],
            ['Access-Control-Allow-Methods', implode(', ', array_map('strval', $this->config->arr('cors.allow_methods')))],
            ['Access-Control-Allow-Headers', implode(', ', array_map('strval', $this->config->arr('cors.allow_headers')))],
            ['Access-Control-Max-Age', (string) $this->config->int('cors.max_age', 600)],
        ];

        if ($value !== '*') {
            $headers[] = ['Vary', 'Origin'];
        }

        if ($this->config->bool('cors.credentials', false)) {
            $headers[] = ['Access-Control-Allow-Credentials', 'true'];
        }

        $expose = array_map('strval', $this->config->arr('cors.expose_headers'));

        if ($expose !== []) {
            $headers[] = ['Access-Control-Expose-Headers', implode(', ', $expose)];
        }

        return $headers;
    }

    /**
     * نام هدر را به شکل Canonical-Case درمی‌آورد.
     */
    private function headerName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9\-_]/', '', $name) ?? $name;

        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }

    /**
     * جلوگیری از header injection (CR/LF داخل مقدار هدر).
     */
    private function sanitizeValue(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }
}
