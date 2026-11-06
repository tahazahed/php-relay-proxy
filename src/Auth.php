<?php

namespace Relay;

/**
 * احراز هویت کلاینت: کلید ثابت، امضای HMAC و لیست سفید IP.
 */
class Auth
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * کلید موجود در مسیر را (اگر فعال باشد) برمی‌گرداند تا TargetResolver
     * بداند کدام بخش مسیر کلید است و آن را از مقصد جدا کند.
     */
    public function keyFromPathEnabled(): bool
    {
        return $this->config->bool('auth.allow_key_in_path', true);
    }

    /**
     * کلیدهای تعریف‌شده در تنظیمات که واقعاً قابل استفاده‌اند.
     *
     * @return array<string,string> label => secret
     */
    public function definedKeys(): array
    {
        $keys = [];

        foreach ($this->config->arr('auth.keys') as $label => $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }

            $keys[(string) $label] = $secret;
        }

        return $keys;
    }

    /**
     * بررسی لیست سفید IP. در صورت رد شدن، استثنا پرتاب می‌شود.
     */
    public function assertIpAllowed(string $ip): void
    {
        $allowlist = $this->config->arr('auth.ip_allowlist');

        if ($allowlist === []) {
            return;
        }

        if (!Util::ipInList($ip, array_map('strval', $allowlist))) {
            throw RelayException::forbidden(
                'ip_not_allowed',
                'IP شما اجازه دسترسی به این رله را ندارد.',
                ['ip' => $ip]
            );
        }
    }

    /**
     * احراز هویت درخواست. برچسب کلید (برای لاگ و rate limit) برگردانده می‌شود.
     *
     * @param string|null $keyFromPath کلیدی که از مسیر استخراج شده
     * @param string      $targetUrl   برای بررسی امضای HMAC لازم است
     */
    public function authenticate(IncomingRequest $request, ?string $keyFromPath, string $targetUrl): string
    {
        $this->assertIpAllowed($request->clientIp());

        if ($this->config->bool('auth.hmac.enabled')) {
            return $this->verifyHmac($request, $targetUrl);
        }

        if (!$this->config->bool('auth.require_key', true)) {
            return 'anonymous';
        }

        $definedKeys = $this->definedKeys();

        if ($definedKeys === []) {
            throw new RelayException(
                500,
                'no_keys_configured',
                'هیچ کلیدی در تنظیمات تعریف نشده است. auth.keys را پر کنید.'
            );
        }

        $presented = $this->extractKey($request, $keyFromPath);

        if ($presented === null || $presented === '') {
            throw RelayException::unauthorized(
                'missing_key',
                'کلید دسترسی ارسال نشده است.'
            );
        }

        foreach ($definedKeys as $label => $secret) {
            if (hash_equals($secret, $presented)) {
                return (string) $label;
            }
        }

        throw RelayException::unauthorized('invalid_key', 'کلید دسترسی نامعتبر است.');
    }

    /**
     * کلید را از هدر، کوئری یا مسیر می‌خواند.
     */
    private function extractKey(IncomingRequest $request, ?string $keyFromPath): ?string
    {
        $headerName = $this->config->str('auth.header', 'X-Relay-Key');

        if ($headerName !== '' && $request->hasHeader($headerName)) {
            return trim($request->header($headerName));
        }

        // پشتیبانی از Authorization: Bearer <key>
        $authorization = $request->header('authorization');

        if ($authorization !== '' && stripos($authorization, 'relay ') === 0) {
            return trim(substr($authorization, 6));
        }

        $queryParam = $this->config->str('auth.query_param', 'relay_key');

        if ($queryParam !== '') {
            $value = $request->query($queryParam);

            if ($value !== '') {
                return $value;
            }
        }

        if ($this->keyFromPathEnabled() && $keyFromPath !== null && $keyFromPath !== '') {
            return $keyFromPath;
        }

        return null;
    }

    /**
     * بررسی امضای HMAC.
     */
    private function verifyHmac(IncomingRequest $request, string $targetUrl): string
    {
        $secret = $this->config->str('auth.hmac.secret');

        if ($secret === '') {
            throw new RelayException(
                500,
                'hmac_not_configured',
                'حالت HMAC فعال است ولی auth.hmac.secret خالی است.'
            );
        }

        $tsHeader  = $this->config->str('auth.hmac.ts_header', 'X-Relay-Timestamp');
        $sigHeader = $this->config->str('auth.hmac.sig_header', 'X-Relay-Signature');
        $tsParam   = $this->config->str('auth.hmac.ts_param', 'relay_ts');
        $sigParam  = $this->config->str('auth.hmac.sig_param', 'relay_sig');

        $timestamp = $request->hasHeader($tsHeader) ? $request->header($tsHeader) : $request->query($tsParam);
        $signature = $request->hasHeader($sigHeader) ? $request->header($sigHeader) : $request->query($sigParam);

        if ($timestamp === '' || $signature === '') {
            throw RelayException::unauthorized('missing_signature', 'timestamp یا signature ارسال نشده است.');
        }

        $ttl   = max(1, $this->config->int('auth.hmac.ttl', 300));
        $drift = abs(time() - (int) $timestamp);

        if ($drift > $ttl) {
            throw RelayException::unauthorized(
                'signature_expired',
                'امضا منقضی شده است (اختلاف زمانی ' . $drift . ' ثانیه).'
            );
        }

        $payload  = $request->method() . "\n" . $targetUrl . "\n" . $timestamp;
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, strtolower(trim($signature)))) {
            throw RelayException::unauthorized('invalid_signature', 'امضای درخواست نامعتبر است.');
        }

        return 'hmac';
    }
}
