<?php

/**
 * PHP Relay Proxy — نقطه ورود
 *
 * همه درخواست‌ها (به‌جای panel.php) توسط .htaccess به این فایل هدایت می‌شوند.
 *
 * نمونه استفاده:
 *   GET https://relay.example.com/r/<KEY>/https://api.telegram.org/bot<TOKEN>/getMe
 *
 * @author  Taha Zahed <https://github.com/tahazahed>
 * @link    https://github.com/tahazahed/php-relay-proxy
 * @license MIT
 */

declare(strict_types=1);

// باید قبل از هر خروجی اجرا شود تا استریم درست کار کند
require __DIR__ . '/src/Emitter.php';
\Relay\Emitter::prepare();

/** @var array{0:\Relay\Config,1:\Relay\Logger} $relay */
$relay = require __DIR__ . '/bootstrap.php';

[$config, $logger] = $relay;

// اسکریپت تا آخر کار ادامه دهد حتی اگر کلاینت قطع کند
// (تا آپلود/دانلود نیمه‌کاره در سمت مقصد رها نشود)
ignore_user_abort(false);

// محدودیت زمان اجرا را با تایم‌اوت درخواست هم‌راستا می‌کنیم
$timeout = $config->int('http.timeout', 120);
@set_time_limit($timeout > 0 ? $timeout + 30 : 0);

$proxy = new \Relay\Proxy($config, $logger);
$proxy->handle();
