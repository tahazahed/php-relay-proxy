<?php

/**
 * ساخت کلید دسترسی و هش رمز پنل.
 *
 * استفاده:
 *   php tools/keygen.php                 → یک کلید تصادفی
 *   php tools/keygen.php "رمز پنل من"    → کلید + هش رمز پنل
 *
 * اگر به خط فرمان دسترسی ندارید، در پنل هم ابزار ساخت کلید هست.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'این ابزار فقط از خط فرمان اجرا می‌شود.';
    exit(1);
}

echo PHP_EOL;
echo 'کلید دسترسی (در auth.keys بگذارید):' . PHP_EOL;
echo '  ' . bin2hex(random_bytes(24)) . PHP_EOL . PHP_EOL;

echo 'کلید HMAC (اگر auth.hmac را فعال می‌کنید):' . PHP_EOL;
echo '  ' . bin2hex(random_bytes(32)) . PHP_EOL . PHP_EOL;

if (isset($argv[1]) && $argv[1] !== '') {
    echo 'هش رمز پنل (در panel.password_hash بگذارید و panel.password را خالی کنید):' . PHP_EOL;
    echo '  ' . password_hash($argv[1], PASSWORD_DEFAULT) . PHP_EOL . PHP_EOL;
}
