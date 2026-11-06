<?php

/**
 * نمونه راه‌اندازی بات تلگرام با رله — این فایل روی سرور ایران اجرا می‌شود.
 *
 * دو مسیر جدا وجود دارد:
 *
 *   ۱) خروجی (شما → تلگرام): همه فراخوانی‌های API از رله عبور می‌کنند.
 *   ۲) ورودی (تلگرام → شما): تلگرام باید بتواند به سرور شما برسد.
 *      - اگر دامنه سرور شما از بیرون قابل دسترسی است، setWebhook را
 *        مستقیم روی همان دامنه تنظیم کنید. (تلگرام فیلتر نیست، شما فیلترید.)
 *      - اگر نه، از getUpdates با long polling استفاده کنید که کاملاً
 *        از سمت شما آغاز می‌شود و به وبهوک نیازی ندارد.
 */

declare(strict_types=1);

const RELAY_BASE = 'https://relay.example.com';
const RELAY_KEY  = '0123456789abcdef0123456789abcdef01234567';
const BOT_TOKEN  = 'BOT_TOKEN_HERE';

/**
 * آدرس رله برای یک متد تلگرام.
 */
function tg_url(string $method): string
{
    return rtrim(RELAY_BASE, '/')
        . '/r/' . rawurlencode(RELAY_KEY)
        . '/https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
}

/**
 * فراخوانی متد Bot API از طریق رله.
 *
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function tg(string $method, array $params = []): array
{
    $handle = curl_init(tg_url($method));

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => $params !== [],
        CURLOPT_POSTFIELDS     => $params !== [] ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 65, // برای long polling باید از timeout تلگرام بیشتر باشد
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($handle);
    $error    = curl_error($handle);

    curl_close($handle);

    if ($response === false) {
        throw new RuntimeException('خطای رله: ' . $error);
    }

    $decoded = json_decode((string) $response, true);

    return is_array($decoded) ? $decoded : ['ok' => false, 'raw' => $response];
}

/**
 * دانلود فایل از تلگرام از طریق رله.
 * (مسیر فایل‌ها با مسیر API فرق دارد: /file/bot<TOKEN>/<file_path>)
 */
function tg_download(string $filePath, string $saveTo): bool
{
    $url = rtrim(RELAY_BASE, '/')
        . '/r/' . rawurlencode(RELAY_KEY)
        . '/https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . $filePath;

    $out = fopen($saveTo, 'wb');

    if ($out === false) {
        return false;
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_FILE    => $out,
        CURLOPT_TIMEOUT => 300,
    ]);

    $ok = curl_exec($handle) !== false;

    curl_close($handle);
    fclose($out);

    return $ok;
}

// ─────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli') {
    $command = $argv[1] ?? 'info';

    if ($command === 'info') {
        print_r(tg('getMe'));
    } elseif ($command === 'set-webhook') {
        // آدرس سرور خودتان که تلگرام باید به آن پوش کند
        print_r(tg('setWebhook', [
            'url'             => 'https://your-iran-server.com/telegram-webhook.php',
            'allowed_updates' => ['message', 'callback_query'],
        ]));
    } elseif ($command === 'poll') {
        // long polling — بدون نیاز به وبهوک
        $offset = 0;

        while (true) {
            $updates = tg('getUpdates', ['offset' => $offset, 'timeout' => 50]);

            foreach ($updates['result'] ?? [] as $update) {
                $offset = ((int) $update['update_id']) + 1;
                $text   = $update['message']['text'] ?? '';
                $chatId = $update['message']['chat']['id'] ?? null;

                echo "پیام: {$text}\n";

                if ($chatId !== null && $text !== '') {
                    tg('sendMessage', ['chat_id' => $chatId, 'text' => 'دریافت شد: ' . $text]);
                }
            }
        }
    }

    exit(0);
}

// ─────────────────────────────────────────────────────────────────────
// حالت وبهوک: تلگرام این فایل را روی سرور شما صدا می‌زند
$update = json_decode((string) file_get_contents('php://input'), true);

if (is_array($update) && isset($update['message']['chat']['id'])) {
    tg('sendMessage', [
        'chat_id' => $update['message']['chat']['id'],
        'text'    => 'پیام شما رسید.',
    ]);
}

http_response_code(200);
echo 'ok';
