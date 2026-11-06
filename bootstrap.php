<?php

/**
 * راه‌اندازی اولیه: autoloader، تنظیمات و بررسی حداقل نیازها.
 *
 * این فایل نباید مستقیماً از مرورگر صدا زده شود.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'این اسکریپت به PHP 7.4 یا بالاتر نیاز دارد. نسخه فعلی: ' . PHP_VERSION;
    exit(1);
}

define('RELAY_ROOT', __DIR__);

// ── autoloader ساده برای namespace‌ی Relay ───────────────────────────
spl_autoload_register(static function (string $class): void {
    $prefix = 'Relay\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = RELAY_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// ── تنظیمات ─────────────────────────────────────────────────────────
// اگر config.php بیرون از پوشه عمومی سایت باشد به آن اولویت می‌دهیم.
// این‌طور اگر روزی هندلر PHP هاست خراب شود، فایل خام کلیدها سرو نمی‌شود.
$relayConfigPath = RELAY_ROOT . '/config.php';

foreach (
    [
        (string) getenv('RELAY_CONFIG'),
        dirname(RELAY_ROOT) . '/relay-private/config.php',
    ] as $relayConfigCandidate
) {
    if ($relayConfigCandidate !== '' && is_file($relayConfigCandidate)) {
        $relayConfigPath = $relayConfigCandidate;
        break;
    }
}

try {
    $relayConfig = \Relay\Config::load($relayConfigPath);
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        [
            'ok'      => false,
            'error'   => 'config_error',
            'message' => $exception->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit(1);
}

define('RELAY_CONFIG_PATH', $relayConfigPath);

// ── تنظیمات قابل‌ویرایش از پنل (کلیدها و مقصدها) ────────────────────
// اگر settings.json موجود باشد، بر auth.keys و target.host_allowlist
// اولویت کامل دارد. بار اول از روی config.php ساخته می‌شود.
$relaySettings = \Relay\Settings::load(
    \Relay\Settings::pathFor($relayConfigPath),
    $relayConfig
);

$relaySettings->applyTo($relayConfig);

date_default_timezone_set($relayConfig->str('app.timezone', 'UTC') ?: 'UTC');

// خطاها هرگز نباید داخل بدنه پاسخ پروکسی چاپ شوند
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting($relayConfig->bool('app.debug') ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE));

$relayLogger = new \Relay\Logger($relayConfig);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use ($relayLogger): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }

    $relayLogger->warning('خطای PHP: ' . $message, ['file' => $file . ':' . $line]);

    return true;
});

if ($relaySettings->loadError() !== null) {
    $relayLogger->warning('settings.json: ' . $relaySettings->loadError());
}

return [$relayConfig, $relayLogger, $relaySettings];
