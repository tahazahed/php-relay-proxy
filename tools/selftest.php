<?php

/**
 * بررسی سلامت نصب.
 *
 * از خط فرمان:   php tools/selftest.php
 * از پنل:        دکمه «بررسی سلامت»
 *
 * این فایل از طریق وب قابل دسترسی نیست (tools/.htaccess).
 */

declare(strict_types=1);

use Relay\Config;
use Relay\Util;

/**
 * @param bool $includeConnectivity تست اتصال خروجی کند است (تا چند ثانیه)،
 *                                  پس در پنل فقط با درخواست کاربر اجرا می‌شود.
 * @return array<int,array{name:string,status:string,detail:string}>
 */
function relay_selftest(Config $config, bool $includeConnectivity = true): array
{
    $checks = [];

    $add = static function (string $name, string $status, string $detail) use (&$checks): void {
        $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    };

    // ── محیط PHP ────────────────────────────────────────────────────
    $add(
        'نسخه PHP',
        PHP_VERSION_ID >= 70400 ? 'ok' : 'fail',
        PHP_VERSION . (PHP_VERSION_ID >= 70400 ? '' : ' — حداقل 7.4 لازم است')
    );

    if (function_exists('curl_version')) {
        $curl = curl_version();
        $add(
            'افزونه cURL',
            'ok',
            'نسخه ' . ($curl['version'] ?? '?') . ' / SSL: ' . ($curl['ssl_version'] ?? '?')
        );
    } else {
        $add(
            'افزونه cURL',
            ini_get('allow_url_fopen') ? 'warn' : 'fail',
            ini_get('allow_url_fopen')
                ? 'فعال نیست — از stream wrapper استفاده می‌شود (آپلود حجیم استریم نمی‌شود)'
                : 'فعال نیست و allow_url_fopen هم خاموش است — رله کار نمی‌کند'
        );
    }

    $add(
        'allow_url_fopen',
        ini_get('allow_url_fopen') ? 'ok' : 'warn',
        ini_get('allow_url_fopen') ? 'روشن' : 'خاموش (فقط اگر cURL نبود مشکل است)'
    );

    $add(
        'افزونه openssl',
        extension_loaded('openssl') ? 'ok' : 'fail',
        extension_loaded('openssl') ? 'فعال' : 'برای HTTPS لازم است'
    );

    $add(
        'افزونه json',
        extension_loaded('json') ? 'ok' : 'fail',
        extension_loaded('json') ? 'فعال' : 'لازم است'
    );

    // ── محدودیت‌های اجرایی ──────────────────────────────────────────
    $maxExecution = (int) ini_get('max_execution_time');
    $add(
        'max_execution_time',
        ($maxExecution === 0 || $maxExecution >= 60) ? 'ok' : 'warn',
        ($maxExecution === 0 ? 'بی‌نهایت' : $maxExecution . ' ثانیه')
        . ' — برای فایل حجیم بهتر است ۳۰۰ باشد'
    );

    $postMax   = Util::iniBytes((string) ini_get('post_max_size'));
    $uploadMax = Util::iniBytes((string) ini_get('upload_max_filesize'));
    $maxBody   = $config->int('http.max_request_body', 0);

    $add(
        'post_max_size',
        ($maxBody === 0 || $postMax >= $maxBody) ? 'ok' : 'warn',
        Util::humanBytes($postMax) . ' (تنظیم رله: ' . Util::humanBytes($maxBody) . ')'
    );

    $add(
        'upload_max_filesize',
        ($maxBody === 0 || $uploadMax >= $maxBody) ? 'ok' : 'warn',
        Util::humanBytes($uploadMax)
    );

    $memory = Util::iniBytes((string) ini_get('memory_limit'));
    $add(
        'memory_limit',
        ($memory <= 0 || $memory >= 134217728) ? 'ok' : 'warn',
        $memory <= 0 ? 'بی‌نهایت' : Util::humanBytes($memory)
    );

    $add(
        'zlib.output_compression',
        ini_get('zlib.output_compression') ? 'warn' : 'ok',
        ini_get('zlib.output_compression')
            ? 'روشن است — می‌تواند Content-Length پاسخ را خراب کند'
            : 'خاموش'
    );

    // ── نوشتنی بودن پوشه‌ها ─────────────────────────────────────────
    foreach (
        [
            'پوشه لاگ'          => $config->str('log.dir'),
            'پوشه محدودیت نرخ'  => $config->str('rate_limit.dir'),
        ] as $label => $dir
    ) {
        if ($dir === '') {
            continue;
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $add(
            $label,
            is_dir($dir) && is_writable($dir) ? 'ok' : 'warn',
            $dir . (is_dir($dir) && is_writable($dir) ? ' — قابل نوشتن' : ' — قابل نوشتن نیست')
        );
    }

    // ── تنظیمات امنیتی ─────────────────────────────────────────────
    $keys       = $config->arr('auth.keys');
    $weakKeys   = [];
    $shortKeys  = [];

    foreach ($keys as $label => $secret) {
        if (!is_string($secret)) {
            continue;
        }

        if (stripos($secret, 'CHANGE_ME') !== false) {
            $weakKeys[] = (string) $label;
        } elseif (strlen($secret) < 24) {
            $shortKeys[] = (string) $label;
        }
    }

    if (!$config->bool('auth.require_key', true) && !$config->bool('auth.hmac.enabled')) {
        $add('احراز هویت', 'fail', 'require_key خاموش است — رله برای همه باز است');
    } elseif ($keys === [] && !$config->bool('auth.hmac.enabled')) {
        $add('احراز هویت', 'fail', 'هیچ کلیدی تعریف نشده است');
    } elseif ($weakKeys !== []) {
        $add('احراز هویت', 'fail', 'کلید پیش‌فرض تغییر نکرده: ' . implode(', ', $weakKeys));
    } elseif ($shortKeys !== []) {
        $add('احراز هویت', 'warn', 'کلید کوتاه است (کمتر از ۲۴ کاراکتر): ' . implode(', ', $shortKeys));
    } else {
        $add('احراز هویت', 'ok', count($keys) . ' کلید فعال');
    }

    $allowlist = $config->arr('target.host_allowlist');
    $add(
        'لیست سفید دامنه',
        $allowlist === [] ? 'warn' : 'ok',
        $allowlist === []
            ? 'خالی است — رله به هر دامنه‌ای درخواست می‌فرستد (open proxy)'
            : count($allowlist) . ' الگو: ' . implode(', ', array_slice(array_map('strval', $allowlist), 0, 6))
    );

    $add(
        'مسدودسازی شبکه داخلی',
        $config->bool('target.block_private_networks', true) ? 'ok' : 'fail',
        $config->bool('target.block_private_networks', true)
            ? 'فعال'
            : 'خاموش — خطر SSRF به شبکه داخلی هاست'
    );

    $add(
        'بررسی گواهی TLS',
        $config->bool('http.verify_tls', true) ? 'ok' : 'warn',
        $config->bool('http.verify_tls', true) ? 'فعال' : 'خاموش — مستعد حمله MITM'
    );

    $add(
        'حالت debug',
        $config->bool('app.debug') ? 'warn' : 'ok',
        $config->bool('app.debug') ? 'روشن است — روی سرور واقعی خاموش کنید' : 'خاموش'
    );

    $panelPassword = $config->str('panel.password');
    $panelHash     = $config->str('panel.password_hash');

    if (!$config->bool('panel.enabled', true)) {
        $add('پنل', 'ok', 'غیرفعال است');
    } elseif ($panelHash === '' && (stripos($panelPassword, 'CHANGE_ME') !== false || $panelPassword === '')) {
        $add('پنل', 'fail', 'رمز پنل تنظیم نشده یا پیش‌فرض است');
    } else {
        $add('پنل', $panelHash === '' ? 'warn' : 'ok', $panelHash === '' ? 'رمز به شکل متن ساده است (password_hash بهتر است)' : 'رمز هش‌شده');
    }

    // ── دسترسی خروجی واقعی ──────────────────────────────────────────
    if ($includeConnectivity) {
        $checks = array_merge($checks, relay_connectivity_checks($config));
    }

    return $checks;
}

/**
 * تست واقعی اتصال خروجی هاست.
 *
 * @return array<int,array{name:string,status:string,detail:string}>
 */
function relay_connectivity_checks(Config $config): array
{
    $results = [];
    $targets = [
        'DNS + HTTPS عمومی' => 'https://www.cloudflare.com/cdn-cgi/trace',
        'دسترسی به تلگرام'  => 'https://api.telegram.org/',
    ];

    foreach ($targets as $label => $url) {
        $started = microtime(true);

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => $config->bool('http.verify_tls', true),
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($handle);
            curl_close($handle);
        } else {
            $context = stream_context_create([
                'http' => ['method' => 'HEAD', 'timeout' => 12, 'ignore_errors' => true],
            ]);
            $handle = @fopen($url, 'rb', false, $context);
            $status = 0;
            $error  = $handle === false ? 'اتصال برقرار نشد' : '';

            if ($handle !== false) {
                $meta = stream_get_meta_data($handle);

                if (!empty($meta['wrapper_data'][0])) {
                    $parts  = explode(' ', (string) $meta['wrapper_data'][0]);
                    $status = isset($parts[1]) ? (int) $parts[1] : 0;
                }

                fclose($handle);
            }
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        $results[] = [
            'name'   => $label,
            'status' => $status > 0 ? 'ok' : 'fail',
            'detail' => $status > 0
                ? 'HTTP ' . $status . ' در ' . $ms . ' میلی‌ثانیه'
                : 'ناموفق: ' . ($error !== '' ? $error : 'پاسخی دریافت نشد'),
        ];
    }

    return $results;
}

// ── اجرای CLI ───────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    /** @var array{0:Config,1:\Relay\Logger} $relay */
    $relay  = require dirname(__DIR__) . '/bootstrap.php';
    $config = $relay[0];

    $icons = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
    $fails = 0;

    echo PHP_EOL . 'PHP Relay Proxy — بررسی سلامت' . PHP_EOL . str_repeat('=', 60) . PHP_EOL;

    foreach (relay_selftest($config) as $check) {
        $fails += $check['status'] === 'fail' ? 1 : 0;

        // printf با %-28s بر مبنای بایت پر می‌کند و ستون‌ها با متن فارسی
        // به هم می‌ریزد، پس خودمان بر مبنای کاراکتر پر می‌کنیم.
        $width   = function_exists('mb_strlen') ? mb_strlen($check['name'], 'UTF-8') : strlen($check['name']);
        $padding = str_repeat(' ', max(1, 30 - $width));

        echo ($icons[$check['status']] ?? '[ ?? ]') . ' ' . $check['name'] . $padding . $check['detail'] . PHP_EOL;
    }

    echo str_repeat('=', 60) . PHP_EOL;
    echo $fails === 0 ? 'همه موارد حیاتی سالم است.' : $fails . ' مورد حیاتی مشکل دارد.';
    echo PHP_EOL . PHP_EOL;

    exit($fails === 0 ? 0 : 1);
}
