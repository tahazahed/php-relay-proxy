<?php
/**
 * PHP Relay Proxy — نمونه فایل تنظیمات
 *
 * این فایل را به config.php کپی کنید و مقادیر را تغییر دهید:
 *   cp config.sample.php config.php
 *
 * هیچ‌وقت config.php را در مخزن عمومی قرار ندهید (کلیدها داخلش هستند).
 */

return [

    // ─────────────────────────────────────────────────────────────
    // عمومی
    // ─────────────────────────────────────────────────────────────
    'app' => [
        // در حالت debug، پیام خطای کامل به کلاینت برگردانده می‌شود.
        // روی سرور واقعی همیشه false باشد.
        'debug'    => false,
        'timezone' => 'UTC',
        // نام هدری که شناسه درخواست در آن برگردانده می‌شود (برای پیدا کردن در لاگ)
        'request_id_header' => 'X-Relay-Request-Id',
    ],

    // ─────────────────────────────────────────────────────────────
    // احراز هویت
    // ─────────────────────────────────────────────────────────────
    'auth' => [
        // اگر false شود، هر کسی می‌تواند از رله استفاده کند. شدیداً توصیه نمی‌شود.
        'require_key' => true,

        // کلیدهای مجاز: 'برچسب' => 'کلید-مخفی'
        // کلید را با این دستور بسازید:  php -r "echo bin2hex(random_bytes(24));"
        // یا از tools/keygen.php استفاده کنید.
        'keys' => [
            'default' => 'CHANGE_ME_0123456789abcdef0123456789abcdef',
        ],

        // کلید از این سه جا خوانده می‌شود (به همین ترتیب اولویت)
        'header'            => 'X-Relay-Key', // هدر HTTP
        'query_param'       => 'relay_key',   // پارامتر کوئری
        'allow_key_in_path' => true,          // /r/<KEY>/https://host/path

        // اگر پر باشد، فقط این IP ها اجازه دارند (لیست IP یا CIDR)
        // مثال: ['1.2.3.4', '5.6.7.0/24']
        'ip_allowlist' => [],

        // حالت امضای HMAC (اختیاری، امن‌تر از کلید ثابت).
        // کلاینت باید relay_ts و relay_sig را بفرستد:
        //   sig = hash_hmac('sha256', METHOD."\n".TARGET_URL."\n".TS, secret)
        'hmac' => [
            'enabled'    => false,
            'secret'     => '',
            'ttl'        => 300,        // ثانیه — پنجره مجاز اختلاف زمان
            'ts_param'   => 'relay_ts',
            'sig_param'  => 'relay_sig',
            'ts_header'  => 'X-Relay-Timestamp',
            'sig_header' => 'X-Relay-Signature',
        ],
    ],

    // ─────────────────────────────────────────────────────────────
    // مقصد (Target)
    // ─────────────────────────────────────────────────────────────
    'target' => [
        // آدرس مقصد از کدام راه‌ها پذیرفته شود
        'query_param' => 'relay_url',      // /?relay_url=https%3A%2F%2Fapi.telegram.org%2F...
        'header'      => 'X-Relay-Target', // X-Relay-Target: https://api.telegram.org/...
        // پیشوندهای مسیر برای حالت شفاف:
        //   /r/<KEY>/https://api.telegram.org/bot123/getMe
        //   /relay/api.telegram.org/bot123/getMe
        'path_prefixes' => ['r', 'relay', 'proxy'],

        'default_scheme'   => 'https',
        'allowed_schemes'  => ['https', 'http'],
        'allowed_ports'    => [80, 443, 8080, 8443],

        // مهم‌ترین تنظیم امنیتی این فایل.
        // لیست سفید دامنه‌های مجاز. اگر خالی بگذارید، رله به «هر» آدرسی
        // درخواست می‌فرستد (open proxy) و در صورت لو رفتن کلید، هاست شما
        // برای سوءاستفاده استفاده می‌شود و ممکن است suspend شود.
        // الگوی wildcard پشتیبانی می‌شود: '*.telegram.org'
        'host_allowlist' => [
            'api.telegram.org',
            '*.telegram.org',
            'core.telegram.org',
            // 'api.openai.com',
            // 'generativelanguage.googleapis.com',
            // 'discord.com',
            // 'api.github.com',
        ],

        // دامنه‌هایی که همیشه رد می‌شوند (بر allowlist اولویت دارد)
        'host_denylist' => [],

        // جلوگیری از SSRF: بستن دسترسی به IP های داخلی/رزرو‌شده
        // (127.0.0.1، 10.x، 192.168.x، ::1، metadata سرورها و ...)
        'block_private_networks' => true,

        // IP دامنه یک بار resolve و به curl پین می‌شود تا حمله
        // DNS rebinding ممکن نباشد. اگر روی هاستتان مشکل ساخت، false کنید.
        'pin_resolved_ip' => true,
    ],

    // ─────────────────────────────────────────────────────────────
    // رفتار HTTP
    // ─────────────────────────────────────────────────────────────
    'http' => [
        'connect_timeout' => 10,
        'timeout'         => 120,   // کل زمان درخواست (ثانیه). 0 = بی‌نهایت
        'max_redirects'   => 3,     // 0 = ریدایرکت را دنبال نکن

        // هدر Location پاسخ از مسیر رله عبور داده شود تا کلاینت
        // مستقیماً به دامنه فیلترشده وصل نشود
        'rewrite_location' => true,

        // حجم درخواست ورودی. پاسخ بدنه بزرگ‌تر از این رد می‌شود.
        'max_request_body'  => 52428800, // 50MB
        'max_response_body' => 0,        // 0 = بی‌حد

        // بدنه‌های بزرگ‌تر از این مقدار به‌صورت استریم آپلود می‌شوند
        // (بدون بارگذاری کامل در حافظه)
        'stream_upload_threshold' => 2097152, // 2MB

        // پاسخ به‌صورت chunk به کلاینت داده شود (برای دانلود فایل بزرگ لازم است)
        'stream_response' => true,

        // قطع اتصال اگر سرعت انتقال زیر این حد بماند (ضد hang)
        'low_speed_limit' => 1,    // بایت بر ثانیه، 0 = غیرفعال
        'low_speed_time'  => 30,   // ثانیه

        'force_ip_version' => 0,   // 0=auto، 4=فقط IPv4، 6=فقط IPv6
        'http_version'     => 0,   // 0=auto، 1.1، 2.0

        'verify_tls' => true,
        'ca_bundle'  => null,      // مسیر cacert.pem اگر هاست CA به‌روز ندارد

        // User-Agent کلاینت عیناً فرستاده شود یا با مقدار پیش‌فرض جایگزین شود
        'user_agent_passthrough' => true,
        'default_user_agent'     => 'RelayProxy/1.0',

        // اگر true شود، IP کلاینت در X-Forwarded-For به مقصد فرستاده می‌شود.
        // برای حفظ حریم خصوصی کلاینت پیش‌فرض false است.
        'send_forwarded_headers' => false,

        // این هدرها از درخواست کلاینت به مقصد فرستاده نمی‌شوند
        'request_header_blocklist' => [
            'host', 'connection', 'keep-alive', 'proxy-authenticate',
            'proxy-authorization', 'te', 'trailer', 'transfer-encoding',
            'upgrade', 'content-length', 'expect',
            'x-relay-key', 'x-relay-target', 'x-relay-timestamp', 'x-relay-signature',
            'cf-connecting-ip', 'cf-ray', 'cf-ipcountry', 'cf-visitor',
            'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto',
            'x-real-ip', 'x-php-ob-level',
        ],

        // این هدرها از پاسخ مقصد به کلاینت فرستاده نمی‌شوند
        'response_header_blocklist' => [
            'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
            'te', 'trailer', 'transfer-encoding', 'upgrade',
            'strict-transport-security',
        ],

        // هدرهای ثابتی که همیشه به مقصد اضافه می‌شوند
        // مثال: ['Authorization' => 'Bearer sk-...'] تا کلید API در سمت کلاینت نباشد
        'extra_request_headers' => [],
    ],

    // ─────────────────────────────────────────────────────────────
    // CORS (فقط اگر می‌خواهید از مرورگر مستقیم صدا بزنید)
    // ─────────────────────────────────────────────────────────────
    'cors' => [
        'enabled'        => false,
        'allow_origins'  => ['*'],
        'allow_headers'  => ['Content-Type', 'Authorization', 'X-Relay-Key', 'X-Relay-Target'],
        'allow_methods'  => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
        'expose_headers' => [],
        'max_age'        => 600,
        'credentials'    => false,
    ],

    // ─────────────────────────────────────────────────────────────
    // محدودیت نرخ (Rate limit)
    // ─────────────────────────────────────────────────────────────
    'rate_limit' => [
        'enabled'      => true,
        'max_requests' => 300,      // در هر پنجره
        'window'       => 60,       // ثانیه
        'by'           => 'key+ip', // key | ip | key+ip
        'dir'          => __DIR__ . '/storage/rate',
    ],

    // ─────────────────────────────────────────────────────────────
    // لاگ
    // ─────────────────────────────────────────────────────────────
    'log' => [
        'enabled'        => true,
        'dir'            => __DIR__ . '/storage/logs',
        'level'          => 'info',  // debug | info | warning | error | off
        'log_bodies'     => false,   // هشدار: بدنه‌ها ممکن است توکن و داده حساس داشته باشند
        'body_limit'     => 2048,

        // سقف حجم لاگ.
        // فایل لاگ روزانه هیچ‌وقت از این تعداد رکورد بیشتر نمی‌شود.
        // وقتی به سقف رسید، به ۸۰٪ سقف کوتاه می‌شود و قدیمی‌ترین
        // رکوردها می‌روند. این «فاصله» عمدی است: باعث می‌شود برش
        // به‌جای هر درخواست، یک بار در هر ۲۰٪ ظرفیت انجام شود.
        // با ۲۰۰۰ رکورد، فایل حدود ۵۰۰ کیلوبایت می‌ماند.
        // 0 = بدون سقف (فایل بی‌نهایت بزرگ می‌شود).
        'max_records'    => 2000,

        'retention_days' => 7,       // لاگ‌های قدیمی‌تر از این تعداد روز پاک می‌شوند
        'max_files'      => 7,       // حداکثر تعداد فایل لاگ. 0 = بدون سقف
        // این عبارت‌ها در لاگ ماسک می‌شوند
        'redact_patterns' => [
            '/bot\d{5,}:[A-Za-z0-9_\-]{20,}/',      // توکن بات تلگرام
            '/(sk|pk|rk)-[A-Za-z0-9_\-]{16,}/',      // کلیدهای OpenAI-مانند
            '/[A-Fa-f0-9]{32,}/',                    // کلیدهای hex بلند
        ],
    ],

    // ─────────────────────────────────────────────────────────────
    // بازنویسی پاسخ (اختیاری)
    // آدرس‌های مقصد داخل بدنه پاسخ را با آدرس رله عوض می‌کند.
    // مثلاً وقتی تلگرام در پاسخ لینک api.telegram.org می‌دهد.
    // {relay} = آدرس پایه رله همین درخواست (مثل https://relay.example.com/r/KEY)
    // ─────────────────────────────────────────────────────────────
    'rewrite' => [
        'enabled'       => false,
        'content_types' => ['application/json', 'text/', 'application/xml'],
        'max_size'      => 1048576, // بدنه بزرگ‌تر بازنویسی نمی‌شود
        'rules' => [
            ['from' => 'https://api.telegram.org', 'to' => '{relay}/https://api.telegram.org'],
        ],
    ],

    // ─────────────────────────────────────────────────────────────
    // پنل مدیریت (panel.php)
    // ─────────────────────────────────────────────────────────────
    'panel' => [
        'enabled' => true,
        // یکی از این دو را پر کنید. hash امن‌تر است:
        //   php -r "echo password_hash('رمز-شما', PASSWORD_DEFAULT);"
        'password'      => 'CHANGE_ME_PANEL_PASSWORD',
        'password_hash' => '',
        'session_name'  => 'relay_panel',
        // اگر پر باشد فقط این IP ها به پنل دسترسی دارند
        'ip_allowlist'  => [],
    ],
];
