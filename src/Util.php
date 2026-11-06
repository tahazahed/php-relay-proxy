<?php

namespace Relay;

/**
 * توابع کمکی مشترک.
 */
class Util
{
    /**
     * IP واقعی کلاینت. چون این اسکریپت معمولاً پشت Cloudflare/LiteSpeed هاست
     * اجرا می‌شود، هدرهای رایج هم بررسی می‌شوند.
     */
    public static function clientIp(): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            // X-Forwarded-For می‌تواند لیست باشد؛ اولین مقدار IP کلاینت است.
            $value = explode(',', (string) $_SERVER[$key])[0];
            $value = trim($value);

            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }

        return '0.0.0.0';
    }

    /**
     * تطبیق نام دامنه با الگو. الگو می‌تواند دقیق یا wildcard باشد:
     *   'api.telegram.org'  → فقط همان
     *   '*.telegram.org'    → هر زیردامنه (و خود telegram.org)
     *   '*'                 → همه
     */
    public static function hostMatches(string $host, string $pattern): bool
    {
        $host    = strtolower(rtrim($host, '.'));
        $pattern = strtolower(trim($pattern));

        if ($pattern === '' ) {
            return false;
        }

        if ($pattern === '*') {
            return true;
        }

        if (strpos($pattern, '*.') === 0) {
            $suffix = substr($pattern, 2);

            return $host === $suffix || self::endsWith($host, '.' . $suffix);
        }

        return $host === $pattern;
    }

    /**
     * @param array<int,string> $patterns
     */
    public static function hostInList(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && self::hostMatches($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * بررسی عضویت IP در لیست IP/CIDR.
     *
     * @param array<int,string> $list
     */
    public static function ipInList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (strpos($entry, '/') !== false) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }

            if (strcasecmp($ip, $entry) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$subnet, $bitsRaw] = $parts;
        $bits = (int) $bitsRaw;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder  = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }

    /**
     * آیا این IP در محدوده‌های خصوصی/رزرو‌شده است؟ (ضد SSRF)
     */
    public static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // چیزی که IP نیست را امن فرض نمی‌کنیم
        }

        // فیلتر داخلی PHP بیشتر محدوده‌ها را می‌گیرد
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($public === false) {
            return true;
        }

        // محدوده‌هایی که فیلتر PHP پوشش نمی‌دهد
        $blocked = [
            '0.0.0.0/8',          // this network
            '100.64.0.0/10',      // CGNAT
            '127.0.0.0/8',        // loopback
            '169.254.0.0/16',     // link-local (metadata سرورهای ابری)
            '192.0.0.0/24',
            '192.0.2.0/24',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',        // multicast
            '240.0.0.0/4',
            '255.255.255.255/32',
            '::/128',
            '::1/128',
            'fc00::/7',           // unique local
            'fe80::/10',          // link-local
            'ff00::/8',           // multicast
            '::ffff:0:0/96',      // IPv4-mapped
            '64:ff9b::/96',       // IPv4/IPv6 translation
            '2002::/16',          // 6to4
        ];

        return self::ipInList($ip, $blocked);
    }

    /**
     * رشته تصادفی hex برای شناسه درخواست.
     */
    public static function randomId(int $bytes = 8): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable $e) {
            return bin2hex(pack('N', mt_rand()) . pack('N', mt_rand()));
        }
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, -strlen($needle)) === $needle;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    /**
     * تبدیل بایت به رشته خوانا.
     */
    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index === 0 ? (string) (int) $value : number_format($value, 2)) . ' ' . $units[$index];
    }

    /**
     * تبدیل مقدار ini مثل "8M" به بایت.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $last   = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        switch ($last) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * آدرس پایه رله برای همین درخواست (بدون اسلش انتهایی).
     * مثال: https://relay.example.com  یا  https://relay.example.com/relay
     */
    public static function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return $scheme . '://' . $host;
    }
}
