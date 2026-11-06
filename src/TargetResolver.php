<?php

namespace Relay;

/**
 * استخراج و اعتبارسنجی آدرس مقصد از درخواست ورودی.
 *
 * حالت‌های پشتیبانی‌شده:
 *   1) مسیر + کلید:   /r/<KEY>/https://api.telegram.org/bot123/getMe
 *   2) مسیر:          /relay/https://api.telegram.org/bot123/getMe
 *   3) مسیر کوتاه:    /https://api.telegram.org/bot123/getMe
 *   4) هدر:           X-Relay-Target: https://api.telegram.org/bot123/getMe
 *   5) کوئری:         /?relay_url=https%3A%2F%2Fapi.telegram.org%2Fbot123%2FgetMe
 */
class TargetResolver
{
    /** @var Config */
    private $config;

    /** @var string|null کلیدی که از مسیر استخراج شد */
    private $keyFromPath = null;

    /** @var string پیشوند مسیر رله برای ساخت آدرس بازگشتی در rewrite */
    private $matchedPrefix = '';

    /** @var string آدرسی که کلاینت داد، قبل از ادغام کوئری (پایه امضای HMAC) */
    private $clientTarget = '';

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function keyFromPath(): ?string
    {
        return $this->keyFromPath;
    }

    public function matchedPrefix(): string
    {
        return $this->matchedPrefix;
    }

    /**
     * آدرس مقصد همان‌طور که کلاینت نوشت (قبل از اضافه شدن کوئری‌های دیگر).
     * امضای HMAC روی همین مقدار محاسبه می‌شود.
     */
    public function clientTarget(): string
    {
        return $this->clientTarget;
    }

    /**
     * آدرس مقصد خام را از درخواست بیرون می‌کشد (بدون اعتبارسنجی امنیتی).
     */
    public function extract(IncomingRequest $request): string
    {
        $fromPath = $this->extractFromPath($request);

        if ($fromPath !== null) {
            return $this->appendQuery($fromPath, $request);
        }

        $headerName = $this->config->str('target.header', 'X-Relay-Target');

        if ($headerName !== '' && $request->hasHeader($headerName)) {
            $value = trim($request->header($headerName));

            if ($value !== '') {
                return $this->appendQuery($value, $request);
            }
        }

        $queryParam = $this->config->str('target.query_param', 'relay_url');

        if ($queryParam !== '') {
            $value = trim($request->query($queryParam));

            if ($value !== '') {
                return $this->appendQuery($value, $request);
            }
        }

        throw RelayException::badRequest(
            'missing_target',
            'آدرس مقصد مشخص نشده است. از /r/<KEY>/https://host/path یا پارامتر '
            . ($queryParam !== '' ? $queryParam : 'relay_url') . ' استفاده کنید.'
        );
    }

    /**
     * مسیر را پارس می‌کند. اگر مسیر حالت رله نبود null برمی‌گرداند.
     */
    private function extractFromPath(IncomingRequest $request): ?string
    {
        $path = ltrim($request->path(), '/');

        if ($path === '') {
            return null;
        }

        // حالت 3: مسیر مستقیماً با آدرس کامل شروع می‌شود
        if ($this->looksLikeAbsoluteUrl($path)) {
            $this->matchedPrefix = '';

            return $path;
        }

        $prefixes = array_map('strval', $this->config->arr('target.path_prefixes'));
        $rest     = null;

        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '/');

            if ($prefix === '') {
                continue;
            }

            if ($path === $prefix) {
                $this->matchedPrefix = $prefix;
                $rest = '';
                break;
            }

            if (Util::startsWith($path, $prefix . '/')) {
                $this->matchedPrefix = $prefix;
                $rest = substr($path, strlen($prefix) + 1);
                break;
            }
        }

        if ($rest === null) {
            return null;
        }

        if ($rest === '') {
            throw RelayException::badRequest(
                'missing_target',
                'بعد از /' . $this->matchedPrefix . '/ آدرس مقصد را بنویسید. '
                . 'مثال: /' . $this->matchedPrefix . '/<KEY>/https://api.telegram.org/bot123/getMe'
            );
        }

        // اگر بلافاصله آدرس کامل آمده، کلیدی در مسیر نیست
        if ($this->looksLikeAbsoluteUrl($rest)) {
            return $rest;
        }

        // در غیر این صورت: بخش اول کلید است
        if ($this->config->bool('auth.allow_key_in_path', true)) {
            $prefixBeforeKey = $this->matchedPrefix;
            $slash           = strpos($rest, '/');

            if ($slash !== false) {
                $this->keyFromPath   = rawurldecode(substr($rest, 0, $slash));
                $this->matchedPrefix = $this->matchedPrefix . '/' . substr($rest, 0, $slash);
                $remaining           = substr($rest, $slash + 1);

                if ($remaining === '') {
                    // کلید را در پیام خطا تکرار نمی‌کنیم تا در لاگ‌های
                    // میانی و Referer لو نرود.
                    throw RelayException::badRequest(
                        'missing_target',
                        'بعد از کلید، آدرس مقصد را بنویسید. مثال: /'
                        . $prefixBeforeKey . '/<KEY>/https://api.telegram.org/bot123/getMe'
                    );
                }

                return $remaining;
            }
        }

        return $rest;
    }

    private function looksLikeAbsoluteUrl(string $value): bool
    {
        return stripos($value, 'http://') === 0 || stripos($value, 'https://') === 0;
    }

    /**
     * پارامترهای کوئری کلاینت (بدون پارامترهای مخصوص رله) را به مقصد اضافه می‌کند.
     */
    private function appendQuery(string $url, IncomingRequest $request): string
    {
        $this->clientTarget = $url;

        $queryString = $request->queryString();

        if ($queryString === '') {
            return $url;
        }

        $reserved = array_filter([
            $this->config->str('auth.query_param', 'relay_key'),
            $this->config->str('target.query_param', 'relay_url'),
            $this->config->str('auth.hmac.ts_param', 'relay_ts'),
            $this->config->str('auth.hmac.sig_param', 'relay_sig'),
        ]);

        $kept = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = rawurldecode(explode('=', $pair, 2)[0]);

            if (in_array($name, $reserved, true)) {
                continue;
            }

            $kept[] = $pair;
        }

        if ($kept === []) {
            return $url;
        }

        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . implode('&', $kept);
    }

    /**
     * اعتبارسنجی امنیتی آدرس مقصد.
     *
     * @param bool $isRedirect اگر true باشد، خطاها با پیام مناسب ریدایرکت داده می‌شوند
     */
    public function validate(string $rawUrl, bool $isRedirect = false): Target
    {
        $url = trim($rawUrl);

        if ($url === '') {
            throw RelayException::badRequest('empty_target', 'آدرس مقصد خالی است.');
        }

        // اگر اسکیم ندارد، اسکیم پیش‌فرض را اضافه کن
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url)) {
            if (strpos($url, '//') === 0) {
                $url = ltrim($url, '/');
            }

            $url = $this->config->str('target.default_scheme', 'https') . '://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw RelayException::badRequest('invalid_target', 'آدرس مقصد معتبر نیست: ' . $url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower(rtrim((string) $parts['host'], '.'));

        $allowedSchemes = array_map('strtolower', array_map('strval', $this->config->arr('target.allowed_schemes', ['https'])));

        if (!in_array($scheme, $allowedSchemes, true)) {
            throw RelayException::forbidden(
                'scheme_not_allowed',
                'پروتکل «' . $scheme . '» مجاز نیست.'
            );
        }

        // اطلاعات ورود داخل URL (user:pass@host) پذیرفته نمی‌شود چون
        // می‌تواند برای دور زدن بررسی دامنه استفاده شود.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw RelayException::forbidden(
                'userinfo_not_allowed',
                'آدرس مقصد نباید شامل نام کاربری/رمز باشد.'
            );
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        $allowedPorts = array_map('intval', $this->config->arr('target.allowed_ports', [80, 443]));

        if ($allowedPorts !== [] && !in_array($port, $allowedPorts, true)) {
            throw RelayException::forbidden('port_not_allowed', 'پورت ' . $port . ' مجاز نیست.');
        }

        $denylist = array_map('strval', $this->config->arr('target.host_denylist'));

        if ($denylist !== [] && Util::hostInList($host, $denylist)) {
            throw RelayException::forbidden('host_denied', 'دامنه ' . $host . ' در لیست سیاه است.');
        }

        $allowlist = array_map('strval', $this->config->arr('target.host_allowlist'));

        if ($allowlist !== [] && !Util::hostInList($host, $allowlist)) {
            throw RelayException::forbidden(
                'host_not_allowed',
                'دامنه ' . $host . ' در لیست سفید رله نیست.'
                . ($isRedirect ? ' (ریدایرکت به این دامنه متوقف شد.)' : '')
            );
        }

        $ips = $this->resolveHost($host);

        if ($this->config->bool('target.block_private_networks', true)) {
            foreach ($ips as $ip) {
                if (Util::isPrivateIp($ip)) {
                    throw RelayException::forbidden(
                        'private_network_blocked',
                        'مقصد به شبکه داخلی اشاره می‌کند و مسدود است.',
                        ['ip' => $ip, 'host' => $host]
                    );
                }
            }
        }

        // بازسازی آدرس نرمال‌شده
        $normalized = $scheme . '://' . $host;

        if (isset($parts['port'])) {
            $normalized .= ':' . $port;
        }

        $normalized .= $parts['path'] ?? '';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . $parts['query'];
        }

        return new Target($normalized, $scheme, $host, $port, $ips);
    }

    /**
     * دامنه را به IP تبدیل می‌کند. برای IP خام همان مقدار برگردانده می‌شود.
     *
     * @return array<int,string>
     */
    private function resolveHost(string $host): array
    {
        // IPv6 داخل براکت
        if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $ips = $v4;
        }

        if ($ips === [] && function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string) $record['ip'];
                    } elseif (!empty($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
        }

        $ips = array_values(array_unique(array_filter($ips)));

        if ($ips === []) {
            throw new RelayException(
                502,
                'dns_failure',
                'دامنه ' . $host . ' قابل resolve نیست. (DNS هاست را بررسی کنید.)'
            );
        }

        return $ips;
    }
}
