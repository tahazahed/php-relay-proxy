<?php

/**
 * نمونه کلاینت — این فایل روی سرور/هاست ایران اجرا می‌شود (نه روی رله).
 *
 * فقط RELAY_BASE و RELAY_KEY را عوض کنید.
 */

declare(strict_types=1);

const RELAY_BASE = 'https://relay.example.com';                       // آدرس هاست خارج از کشور
const RELAY_KEY  = '0123456789abcdef0123456789abcdef01234567';   // کلید از config.php

/**
 * یک درخواست از طریق رله می‌فرستد.
 *
 * @param string                     $targetUrl آدرس واقعی مقصد
 * @param array<string,mixed>|string|null $body  آرایه (JSON یا فرم) یا رشته خام
 * @param array<int,string>          $headers   هدرهای اضافه
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function relay_request(string $targetUrl, string $method = 'GET', $body = null, array $headers = []): array
{
    // حالت مسیر: کلید داخل آدرس. کتابخانه‌هایی که امکان افزودن هدر ندارند
    // هم می‌توانند از همین شکل استفاده کنند.
    $url = rtrim(RELAY_BASE, '/') . '/r/' . rawurlencode(RELAY_KEY) . '/' . $targetUrl;

    $handle = curl_init();

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FOLLOWLOCATION => false,
    ];

    if (is_array($body)) {
        // اگر فایل دارید از CURLFile استفاده کنید تا multipart شود
        $hasFile = false;

        foreach ($body as $value) {
            if ($value instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }

        if ($hasFile) {
            $options[CURLOPT_POSTFIELDS] = $body;
        } else {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[]                   = 'Content-Type: application/json';
        }
    } elseif (is_string($body) && $body !== '') {
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    if ($headers !== []) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($handle, $options);

    $response   = curl_exec($handle);
    $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $error      = curl_error($handle);

    curl_close($handle);

    if ($response === false) {
        throw new RuntimeException('ارتباط با رله برقرار نشد: ' . $error);
    }

    $rawHeaders = substr((string) $response, 0, $headerSize);
    $parsed     = [];

    foreach (explode("\n", $rawHeaders) as $line) {
        $colon = strpos($line, ':');

        if ($colon !== false) {
            $parsed[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }

    return [
        'status'  => $status,
        'headers' => $parsed,
        'body'    => substr((string) $response, $headerSize),
    ];
}

// ─────────────────────────────────────────────────────────────────────
// نمونه‌ها
// ─────────────────────────────────────────────────────────────────────

if (PHP_SAPI === 'cli') {
    $botToken = 'BOT_TOKEN_HERE';

    // ۱) getMe
    $response = relay_request("https://api.telegram.org/bot{$botToken}/getMe");
    echo "getMe → HTTP {$response['status']}\n{$response['body']}\n\n";

    // ۲) ارسال پیام
    $response = relay_request(
        "https://api.telegram.org/bot{$botToken}/sendMessage",
        'POST',
        ['chat_id' => 123456789, 'text' => 'سلام از طریق رله']
    );
    echo "sendMessage → HTTP {$response['status']}\n{$response['body']}\n\n";

    // ۳) ارسال عکس (multipart)
    if (is_file(__DIR__ . '/sample.jpg')) {
        $response = relay_request(
            "https://api.telegram.org/bot{$botToken}/sendPhoto",
            'POST',
            [
                'chat_id' => 123456789,
                'caption' => 'آپلود از طریق رله',
                'photo'   => new CURLFile(__DIR__ . '/sample.jpg', 'image/jpeg', 'sample.jpg'),
            ]
        );
        echo "sendPhoto → HTTP {$response['status']}\n{$response['body']}\n\n";
    }

    // ۴) هر API دیگری
    $response = relay_request('https://api.openai.com/v1/models', 'GET', null, [
        'Authorization: Bearer sk-...',
    ]);
    echo "openai → HTTP {$response['status']}\n";
}
