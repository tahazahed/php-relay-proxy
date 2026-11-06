<?php

namespace Relay;

/**
 * نگه‌دارنده تنظیمات با دسترسی نقطه‌ای: $config->get('http.timeout', 30)
 */
class Config
{
    /** @var array<string,mixed> */
    private $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * فایل config.php را می‌خواند و با مقادیر پیش‌فرض ادغام می‌کند.
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(
                'فایل تنظیمات پیدا نشد: ' . basename($path)
                . ' — ابتدا config.sample.php را به config.php کپی کنید.'
            );
        }

        /** @var mixed $data */
        $data = require $path;

        if (!is_array($data)) {
            throw new \RuntimeException('فایل تنظیمات باید یک آرایه برگرداند.');
        }

        return new self(self::mergeDefaults(self::defaults(), $data));
    }

    /**
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $node = $this->data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * بازنویسی یک مقدار در زمان اجرا (برای اعمال تنظیمات پنل).
     *
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $node     = &$this->data;

        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }

            $node = &$node[$segment];
        }

        $node = $value;
        unset($node);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<mixed> */
    public function arr(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * ادغام بازگشتی: کلیدهای کاربر بر پیش‌فرض اولویت دارند، ولی
     * لیست‌های عددی (مثل allowlist) کامل جایگزین می‌شوند نه ادغام.
     *
     * @param array<mixed> $defaults
     * @param array<mixed> $user
     * @return array<mixed>
     */
    private static function mergeDefaults(array $defaults, array $user): array
    {
        foreach ($user as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !self::isList($value)) {
                $defaults[$key] = self::mergeDefaults($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /** @param array<mixed> $array */
    private static function isList(array $array): bool
    {
        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        $base = dirname(__DIR__);

        return [
            'app' => [
                'debug'             => false,
                'timezone'          => 'UTC',
                'request_id_header' => 'X-Relay-Request-Id',
            ],
            'auth' => [
                'require_key'       => true,
                'keys'              => [],
                'header'            => 'X-Relay-Key',
                'query_param'       => 'relay_key',
                'allow_key_in_path' => true,
                'ip_allowlist'      => [],
                'hmac' => [
                    'enabled'    => false,
                    'secret'     => '',
                    'ttl'        => 300,
                    'ts_param'   => 'relay_ts',
                    'sig_param'  => 'relay_sig',
                    'ts_header'  => 'X-Relay-Timestamp',
                    'sig_header' => 'X-Relay-Signature',
                ],
            ],
            'target' => [
                'query_param'             => 'relay_url',
                'header'                  => 'X-Relay-Target',
                'path_prefixes'           => ['r', 'relay', 'proxy'],
                'default_scheme'          => 'https',
                'allowed_schemes'         => ['https', 'http'],
                'allowed_ports'           => [80, 443, 8080, 8443],
                'host_allowlist'          => [],
                'host_denylist'           => [],
                'block_private_networks'  => true,
                'pin_resolved_ip'         => true,
            ],
            'http' => [
                'connect_timeout'           => 10,
                'timeout'                   => 120,
                'max_redirects'             => 3,
                'rewrite_location'          => true,
                'max_request_body'          => 52428800,
                'max_response_body'         => 0,
                'stream_upload_threshold'   => 2097152,
                'stream_response'           => true,
                'low_speed_limit'           => 1,
                'low_speed_time'            => 30,
                'force_ip_version'          => 0,
                'http_version'              => 0,
                'verify_tls'                => true,
                'ca_bundle'                 => null,
                'user_agent_passthrough'    => true,
                'default_user_agent'        => 'RelayProxy/1.0',
                'send_forwarded_headers'    => false,
                'request_header_blocklist'  => [
                    'host', 'connection', 'keep-alive', 'proxy-authenticate',
                    'proxy-authorization', 'te', 'trailer', 'transfer-encoding',
                    'upgrade', 'content-length', 'expect',
                    'x-relay-key', 'x-relay-target', 'x-relay-timestamp', 'x-relay-signature',
                ],
                'response_header_blocklist' => [
                    'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
                    'te', 'trailer', 'transfer-encoding', 'upgrade',
                ],
                'extra_request_headers'     => [],
            ],
            'cors' => [
                'enabled'        => false,
                'allow_origins'  => ['*'],
                'allow_headers'  => ['Content-Type', 'Authorization', 'X-Relay-Key', 'X-Relay-Target'],
                'allow_methods'  => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
                'expose_headers' => [],
                'max_age'        => 600,
                'credentials'    => false,
            ],
            'rate_limit' => [
                'enabled'      => true,
                'max_requests' => 300,
                'window'       => 60,
                'by'           => 'key+ip',
                'dir'          => $base . '/storage/rate',
            ],
            'log' => [
                'enabled'         => true,
                'dir'             => $base . '/storage/logs',
                'level'           => 'info',
                'log_bodies'      => false,
                'body_limit'      => 2048,
                'max_records'     => 2000,
                'retention_days'  => 7,
                'max_files'       => 7,
                'redact_patterns' => [],
            ],
            'rewrite' => [
                'enabled'       => false,
                'content_types' => ['application/json', 'text/'],
                'max_size'      => 1048576,
                'rules'         => [],
            ],
            'panel' => [
                'enabled'       => true,
                'password'      => '',
                'password_hash' => '',
                'session_name'  => 'relay_panel',
                'ip_allowlist'  => [],
            ],
        ];
    }
}
