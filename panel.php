<?php

/**
 * پنل مدیریت رله: بررسی سلامت، تست درخواست، مشاهده لاگ و راهنمای استفاده.
 *
 * آدرس: https://<دامنه-رله>/panel.php
 * رمز در config.php بخش panel تنظیم می‌شود.
 *
 * این فایل عمداً خودکفاست: هیچ درخواست بیرونی (فونت، CSS، JS) نمی‌زند،
 * چون پنل ممکن است از داخل شبکه‌ای باز شود که CDN ها در دسترس نیستند.
 * فونت وزیرمتن از پوشه assets/ همین هاست سرو می‌شود.
 */

declare(strict_types=1);

use Relay\Util;

/** @var array{0:\Relay\Config,1:\Relay\Logger,2:\Relay\Settings} $relay */
$relay = require __DIR__ . '/bootstrap.php';

[$config, $logger, $settings] = $relay;

if (!$config->bool('panel.enabled', true)) {
    http_response_code(404);
    exit('پنل غیرفعال است.');
}

$panelIpAllowlist = array_map('strval', $config->arr('panel.ip_allowlist'));

if ($panelIpAllowlist !== [] && !Util::ipInList(Util::clientIp(), $panelIpAllowlist)) {
    http_response_code(403);
    exit('IP شما اجازه دسترسی به پنل را ندارد.');
}

// ── نشست ────────────────────────────────────────────────────────────
session_name($config->str('panel.session_name', 'relay_panel') ?: 'relay_panel');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = Util::randomId(16);
}

$csrf     = (string) $_SESSION['csrf'];
$loggedIn = !empty($_SESSION['authed']);
$notice   = null;
$error    = null;

/**
 * بررسی رمز پنل.
 */
$checkPassword = static function (string $input) use ($config): bool {
    $hash = $config->str('panel.password_hash');

    if ($hash !== '') {
        return password_verify($input, $hash);
    }

    $plain = $config->str('panel.password');

    return $plain !== '' && stripos($plain, 'CHANGE_ME') === false && hash_equals($plain, $input);
};

$postedCsrf = (string) ($_POST['csrf'] ?? '');
$action     = (string) ($_POST['action'] ?? '');

if ($action !== '' && !hash_equals($csrf, $postedCsrf)) {
    $error  = 'توکن امنیتی فرم معتبر نیست. صفحه را دوباره باز کنید.';
    $action = '';
}

if ($action === 'login') {
    usleep(300000); // کند کردن حمله brute force

    if ($checkPassword((string) ($_POST['password'] ?? ''))) {
        $_SESSION['authed'] = true;
        $loggedIn           = true;
        $notice             = 'خوش آمدید.';
        $logger->info('ورود موفق به پنل', ['ip' => Util::clientIp()]);
    } else {
        $error = 'رمز اشتباه است.';
        $logger->warning('ورود ناموفق به پنل', ['ip' => Util::clientIp()]);
    }
} elseif ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? 'panel.php'), '?'));
    exit;
}

$tab        = (string) ($_GET['tab'] ?? 'health');
$testResult = null;
$newKey     = null;

if ($loggedIn && $action === 'test') {
    $testResult = relay_panel_test($config, $_POST);
    $tab        = 'test';
}

// ── مدیریت کلیدها و مقصدها ─────────────────────────────────────────
if ($loggedIn && $action === 'password_change') {
    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['new'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    usleep(300000); // کند کردن حدس زدن رمز فعلی

    if (!$settings->writable()) {
        $error = 'فایل settings.json قابل نوشتن نیست؛ رمز ذخیره نمی‌شود.';
    } elseif (!$checkPassword($current)) {
        $error = 'رمز فعلی اشتباه است.';
        $logger->warning('تلاش ناموفق برای تغییر رمز پنل', ['ip' => Util::clientIp()]);
    } elseif ($new !== $confirm) {
        $error = 'رمز جدید و تکرارش یکی نیستند.';
    } elseif ($new === $current) {
        $error = 'رمز جدید با رمز فعلی فرقی ندارد.';
    } else {
        try {
            $settings->setPanelPassword($new);

            // نشست فعلی معتبر می‌ماند ولی توکن را عوض می‌کنیم
            session_regenerate_id(true);
            $_SESSION['csrf'] = Util::randomId(16);
            $csrf             = (string) $_SESSION['csrf'];

            $notice = 'رمز پنل عوض شد. دفعه بعد با رمز جدید وارد شوید.';
            $logger->warning('رمز پنل از پنل عوض شد', ['ip' => Util::clientIp()]);
        } catch (\Relay\RelayException $exception) {
            $error = $exception->getMessage();
        }
    }

    $tab = 'security';
}

if ($loggedIn && in_array($action, ['key_create', 'key_delete', 'key_toggle', 'targets_save'], true)) {
    if (!$settings->writable()) {
        $error = 'فایل settings.json قابل نوشتن نیست؛ تغییرات ذخیره نمی‌شود. '
            . 'دسترسی پوشه ' . dirname($settings->path()) . ' را بررسی کنید.';
        $tab   = $action === 'targets_save' ? 'targets' : 'keys';
    } else {
        try {
            if ($action === 'key_create') {
                $newKey = $settings->createKey(
                    (string) ($_POST['label'] ?? ''),
                    (string) ($_POST['note'] ?? '')
                );
                $notice = 'کلید «' . $newKey['label'] . '» ساخته شد.';
                $logger->info('کلید جدید از پنل ساخته شد', [
                    'label' => $newKey['label'],
                    'ip'    => Util::clientIp(),
                ]);
                $tab = 'keys';
            } elseif ($action === 'key_delete') {
                $target = $settings->findKey((string) ($_POST['id'] ?? ''));

                if ($target !== null && $settings->deleteKey($target['id'])) {
                    $notice = 'کلید «' . $target['label'] . '» حذف شد.';
                    $logger->warning('کلید از پنل حذف شد', [
                        'label' => $target['label'],
                        'ip'    => Util::clientIp(),
                    ]);
                } else {
                    $error = 'کلید پیدا نشد.';
                }

                $tab = 'keys';
            } elseif ($action === 'key_toggle') {
                $target = $settings->findKey((string) ($_POST['id'] ?? ''));
                $state  = $target === null ? null : $settings->toggleKey($target['id']);

                if ($state === null) {
                    $error = 'کلید پیدا نشد.';
                } else {
                    $notice = 'کلید «' . $target['label'] . '» '
                        . ($state ? 'فعال' : 'غیرفعال') . ' شد.';
                    $logger->info('وضعیت کلید عوض شد', [
                        'label'   => $target['label'],
                        'enabled' => $state,
                        'ip'      => Util::clientIp(),
                    ]);
                }

                $tab = 'keys';
            } elseif ($action === 'targets_save') {
                $restrict = !empty($_POST['restrict']);
                $lines    = preg_split('/

|
|
/', (string) ($_POST['hosts'] ?? '')) ?: [];
                $saved    = $settings->saveTargets($restrict, $lines);

                if ($saved['rejected'] !== []) {
                    $error = 'این مقادیر نامعتبر بودند و ذخیره نشدند: '
                        . implode('، ', array_slice($saved['rejected'], 0, 5));
                }

                $notice = $restrict
                    ? 'محدودیت مقصد فعال است با ' . count($saved['saved']) . ' دامنه.'
                    : 'محدودیت مقصد خاموش شد — رله حالا به هر دامنه‌ای درخواست می‌فرستد.';

                $logger->warning('تنظیمات مقصد از پنل عوض شد', [
                    'restrict' => $restrict,
                    'count'    => count($saved['saved']),
                    'ip'       => Util::clientIp(),
                ]);

                $tab = 'targets';
            }
        } catch (\Relay\RelayException $exception) {
            $error = $exception->getMessage();
            $tab   = $action === 'targets_save' ? 'targets' : 'keys';
        }
    }
}

/**
 * آدرس پایه رله (محل نصب اسکریپت).
 */
function relay_panel_base(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');

    return Util::baseUrl() . ($dir === '.' ? '' : $dir);
}

/**
 * یک درخواست واقعی از طریق رله (یا مستقیم) می‌فرستد.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function relay_panel_test(\Relay\Config $config, array $input): array
{
    $targetUrl = trim((string) ($input['target'] ?? ''));
    $method    = strtoupper(trim((string) ($input['method'] ?? 'GET')));
    $keyLabel  = (string) ($input['key'] ?? '');
    $body      = (string) ($input['body'] ?? '');
    $mode      = (string) ($input['mode'] ?? 'relay');

    if ($targetUrl === '') {
        return ['ok' => false, 'message' => 'آدرس مقصد را وارد کنید.'];
    }

    $keys   = $config->arr('auth.keys');
    $secret = isset($keys[$keyLabel]) && is_string($keys[$keyLabel]) ? $keys[$keyLabel] : '';

    if ($mode === 'relay') {
        $url = relay_panel_base() . '/r/' . ($secret !== '' ? rawurlencode($secret) . '/' : '') . $targetUrl;
    } else {
        $url = $targetUrl;
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'cURL در دسترس نیست؛ تست از پنل ممکن نیست.'];
    }

    $handle  = curl_init();
    $headers = ['Accept: */*'];

    if ($body !== '') {
        $headers[] = 'Content-Type: ' . (json_decode($body) !== null ? 'application/json' : 'text/plain');
    }

    curl_setopt_array($handle, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        // هاست ممکن است به خودش با نام دیگری وصل شود
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($body !== '') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    $started  = microtime(true);
    $response = curl_exec($handle);
    $duration = microtime(true) - $started;

    $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $curlError  = curl_error($handle);

    curl_close($handle);

    if ($response === false) {
        return ['ok' => false, 'url' => $url, 'message' => 'درخواست ناموفق بود: ' . $curlError];
    }

    $rawHeaders = substr((string) $response, 0, $headerSize);
    $rawBody    = substr((string) $response, $headerSize);
    $decoded    = json_decode($rawBody, true);

    if (is_array($decoded)) {
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (is_string($pretty)) {
            $rawBody = $pretty;
        }
    }

    return [
        'ok'       => $status >= 200 && $status < 400,
        'url'      => $url,
        'status'   => $status,
        'duration' => round($duration * 1000),
        'headers'  => trim($rawHeaders),
        'body'     => strlen($rawBody) > 20000 ? substr($rawBody, 0, 20000) . "\n…[بریده شد]" : $rawBody,
        'size'     => strlen($rawBody),
    ];
}

/** خروجی امن HTML */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** متن کوتاه برای کد وضعیت HTTP */
function relay_status_tone(int $status): string
{
    if ($status >= 200 && $status < 300) {
        return 'ok';
    }

    if ($status >= 300 && $status < 400) {
        return 'warn';
    }

    return 'fail';
}

/** زمان نسبی فارسی */
function relay_ago(string $iso): string
{
    $time = strtotime($iso);

    if ($time === false) {
        return '';
    }

    $diff = time() - $time;

    if ($diff < 60) {
        return 'چند لحظه پیش';
    }

    if ($diff < 3600) {
        return intdiv($diff, 60) . ' دقیقه پیش';
    }

    if ($diff < 86400) {
        return intdiv($diff, 3600) . ' ساعت پیش';
    }

    return intdiv($diff, 86400) . ' روز پیش';
}

// ── داده هر تب ──────────────────────────────────────────────────────
$logFiles    = $logger->files();
$selectedLog = (string) ($_GET['log'] ?? ($logFiles[0] ?? ''));

if ($selectedLog !== '' && !in_array($selectedLog, $logFiles, true)) {
    $selectedLog = $logFiles[0] ?? '';
}

$logLines = ($loggedIn && $tab === 'logs' && $selectedLog !== '') ? $logger->tail(200, $selectedLog) : [];

$healthChecks  = [];
$healthSummary = ['ok' => 0, 'warn' => 0, 'fail' => 0];
$withNetwork   = isset($_GET['net']);

if ($loggedIn && $tab === 'health') {
    require __DIR__ . '/tools/selftest.php';
    $healthChecks = relay_selftest($config, $withNetwork);

    foreach ($healthChecks as $check) {
        if (isset($healthSummary[$check['status']])) {
            $healthSummary[$check['status']]++;
        }
    }
}

$relayBase  = relay_panel_base();
$keyLabels  = array_keys($config->arr('auth.keys'));
$fontExists = is_file(__DIR__ . '/assets/vazirmatn-var.woff2');

$tabs = [
    'health'  => ['سلامت نصب', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    'keys'    => ['کلیدها', 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'],
    'targets' => ['مقصدها', 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9'],
    'test'    => ['تست درخواست', 'M13 10V3L4 14h7v7l9-11h-7z'],
    'logs'    => ['لاگ‌ها', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    'security' => ['امنیت', 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
    'usage'   => ['راهنما', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.247m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.247'],
];

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>پنل رله</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛰️</text></svg>">
<style>
<?php if ($fontExists) { ?>
/* فونت از همین هاست سرو می‌شود — هیچ درخواستی به CDN بیرونی نمی‌رود */
@font-face {
    font-family: 'Vazirmatn';
    src: url('assets/vazirmatn-var.woff2') format('woff2') tech(variations),
         url('assets/vazirmatn-var.woff2') format('woff2-variations');
    font-weight: 100 900;
    font-style: normal;
    font-display: swap;
}
<?php } ?>

:root {
    color-scheme: light dark;

    --font-ui: <?= $fontExists ? "'Vazirmatn', " : '' ?>"Segoe UI", Tahoma, "Noto Sans Arabic", system-ui, sans-serif;
    --font-mono: ui-monospace, "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace;

    --bg:        #f4f5f7;
    --bg-soft:   #eceef2;
    --surface:   #ffffff;
    --surface-2: #f8f9fb;
    --border:    #dfe3e9;
    --border-2:  #c9cfd8;

    --text:      #1a1d21;
    --text-soft: #5b6471;
    --text-dim:  #8b949e;

    --accent:      #2f5fd8;
    --accent-soft: #e8eeff;
    --accent-text: #ffffff;

    --ok:        #11823b;
    --ok-soft:   #e3f6e9;
    --warn:      #9a6200;
    --warn-soft: #fdf1dc;
    --fail:      #c02626;
    --fail-soft: #fdeaea;

    --shadow: 0 1px 2px rgba(16, 24, 40, .05), 0 1px 3px rgba(16, 24, 40, .06);
    --shadow-lg: 0 4px 6px -2px rgba(16, 24, 40, .04), 0 12px 20px -4px rgba(16, 24, 40, .10);

    --radius:    12px;
    --radius-sm: 8px;
}

@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --bg:        #0f1114;
        --bg-soft:   #15181c;
        --surface:   #181b20;
        --surface-2: #1e2228;
        --border:    #2a2f37;
        --border-2:  #3a4149;

        --text:      #e7eaee;
        --text-soft: #a2abb6;
        --text-dim:  #76808c;

        --accent:      #5b8cff;
        --accent-soft: #1b2541;
        --accent-text: #0f1114;

        --ok:        #4ed07f;
        --ok-soft:   #13301f;
        --warn:      #f1b44c;
        --warn-soft: #332714;
        --fail:      #ff6b6b;
        --fail-soft: #3a1b1b;

        --shadow:    0 1px 2px rgba(0, 0, 0, .4);
        --shadow-lg: 0 12px 24px -6px rgba(0, 0, 0, .55);
    }
}

:root[data-theme="dark"] {
    --bg:        #0f1114;
    --bg-soft:   #15181c;
    --surface:   #181b20;
    --surface-2: #1e2228;
    --border:    #2a2f37;
    --border-2:  #3a4149;

    --text:      #e7eaee;
    --text-soft: #a2abb6;
    --text-dim:  #76808c;

    --accent:      #5b8cff;
    --accent-soft: #1b2541;
    --accent-text: #0f1114;

    --ok:        #4ed07f;
    --ok-soft:   #13301f;
    --warn:      #f1b44c;
    --warn-soft: #332714;
    --fail:      #ff6b6b;
    --fail-soft: #3a1b1b;

    --shadow:    0 1px 2px rgba(0, 0, 0, .4);
    --shadow-lg: 0 12px 24px -6px rgba(0, 0, 0, .55);
}

*, *::before, *::after { box-sizing: border-box; }

html { -webkit-text-size-adjust: 100%; }

body {
    margin: 0;
    padding: 0 16px 72px;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-ui);
    font-size: 14.5px;
    line-height: 1.75;
    font-weight: 400;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

.wrap { max-width: 1060px; margin: 0 auto; }

/* ── عددها همیشه چپ‌به‌راست و هم‌عرض ───────────────────────────── */
.num, td .num, .mono {
    font-variant-numeric: tabular-nums;
    font-feature-settings: "tnum";
}
.mono { font-family: var(--font-mono); direction: ltr; unicode-bidi: isolate; }
.ltr  { direction: ltr; text-align: left; unicode-bidi: isolate; }

/* ── سربرگ ─────────────────────────────────────────────────────── */
.topbar {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    padding: 22px 0 18px;
}

.brand { display: flex; align-items: center; gap: 11px; min-width: 0; }

.brand-mark {
    width: 38px; height: 38px;
    flex: 0 0 38px;
    border-radius: 10px;
    background: var(--accent);
    color: var(--accent-text);
    display: grid; place-items: center;
    box-shadow: var(--shadow);
}
.brand-mark svg { width: 21px; height: 21px; }

.brand-text { min-width: 0; }
.brand-text h1 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    letter-spacing: -.01em;
}
.brand-text p {
    margin: 1px 0 0;
    font-size: 12.5px;
    color: var(--text-soft);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.topbar-actions { display: flex; gap: 8px; margin-inline-start: auto; }

/* ── تب‌ها ──────────────────────────────────────────────────────── */
.tabs {
    display: flex;
    gap: 4px;
    padding: 4px;
    margin-bottom: 20px;
    background: var(--bg-soft);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow-x: auto;
    scrollbar-width: none;
}
.tabs::-webkit-scrollbar { display: none; }

.tabs a {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    color: var(--text-soft);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
    white-space: nowrap;
    transition: background .15s, color .15s;
}
.tabs a svg { width: 15px; height: 15px; opacity: .75; }
.tabs a:hover { background: var(--surface); color: var(--text); }
.tabs a[aria-current="page"] {
    background: var(--surface);
    color: var(--text);
    font-weight: 600;
    box-shadow: var(--shadow);
}
.tabs a[aria-current="page"] svg { opacity: 1; color: var(--accent); }

/* ── کارت ───────────────────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 18px;
    overflow: hidden;
}
.card-head {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}
.card-head h2 { margin: 0; font-size: 15px; font-weight: 650; }
.card-head .spacer { margin-inline-start: auto; }
.card-body { padding: 20px; }
.card-body > :first-child { margin-top: 0; }
.card-body > :last-child { margin-bottom: 0; }

.hint { color: var(--text-soft); font-size: 13px; margin: 0 0 16px; }

/* ── کاشی آمار ──────────────────────────────────────────────────── */
.tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    padding: 20px;
    border-bottom: 1px solid var(--border);
}
.tile {
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface-2);
}
.tile .v {
    display: block;
    font-size: 26px;
    font-weight: 700;
    line-height: 1.25;
    font-variant-numeric: tabular-nums;
}
.tile .k { font-size: 12.5px; color: var(--text-soft); }
.tile.ok   { background: var(--ok-soft);   border-color: transparent; }
.tile.ok .v   { color: var(--ok); }
.tile.warn { background: var(--warn-soft); border-color: transparent; }
.tile.warn .v { color: var(--warn); }
.tile.fail { background: var(--fail-soft); border-color: transparent; }
.tile.fail .v { color: var(--fail); }

/* ── جدول ───────────────────────────────────────────────────────── */
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
th, td { text-align: right; padding: 11px 20px; vertical-align: top; }
thead th {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-soft);
    text-transform: none;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    position: sticky; top: 0;
}
tbody tr + tr td { border-top: 1px solid var(--border); }
tbody tr:hover td { background: var(--surface-2); }
td.name { font-weight: 500; white-space: nowrap; }
td.detail {
    color: var(--text-soft);
    /* توکن‌های بلند مثل OpenSSL/1.1.1s نباید از سلول بیرون بزنند */
    overflow-wrap: anywhere;
    word-break: break-word;
}

/* ── نشان وضعیت ─────────────────────────────────────────────────── */
.pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.pill::before {
    content: "";
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex: 0 0 6px;
}
.pill.ok   { background: var(--ok-soft);   color: var(--ok); }
.pill.warn { background: var(--warn-soft); color: var(--warn); }
.pill.fail { background: var(--fail-soft); color: var(--fail); }
.pill.info { background: var(--accent-soft); color: var(--accent); }
.pill.mute { background: var(--bg-soft); color: var(--text-soft); }
.pill.plain::before { display: none; }

/* ── فرم ────────────────────────────────────────────────────────── */
label { display: block; font-size: 12.5px; font-weight: 500; color: var(--text-soft); margin-bottom: 6px; }
.field { margin-bottom: 16px; }
.field:last-of-type { margin-bottom: 0; }

input[type=text], input[type=password], select, textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid var(--border-2);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font-family: inherit;
    font-size: 13.5px;
    line-height: 1.6;
    transition: border-color .15s, box-shadow .15s;
}
input:focus-visible, select:focus-visible, textarea:focus-visible {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}
textarea {
    min-height: 96px;
    font-family: var(--font-mono);
    font-size: 12.5px;
    direction: ltr;
    text-align: left;
    resize: vertical;
}
select {
    appearance: none;
    background-image: linear-gradient(45deg, transparent 50%, var(--text-dim) 50%),
                      linear-gradient(135deg, var(--text-dim) 50%, transparent 50%);
    background-position: calc(12px) calc(50% - 2px), calc(16px) calc(50% - 2px);
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
    /* فلش فیزیکی سمت چپ است، پس فضا را در inline-end (چپ در RTL) باز می‌کنیم */
    padding-inline-end: 32px;
}

.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: var(--accent);
    color: var(--accent-text);
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: filter .15s, background .15s;
}
.btn:hover { filter: brightness(1.08); }
.btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.btn svg { width: 15px; height: 15px; }

.btn.ghost {
    background: var(--surface);
    color: var(--text-soft);
    border-color: var(--border-2);
}
.btn.ghost:hover { background: var(--surface-2); color: var(--text); filter: none; }

.btn.small { padding: 5px 11px; font-size: 12.5px; font-weight: 500; }
.btn.icon { padding: 6px; }
.btn.icon svg { width: 14px; height: 14px; }

.form-actions { margin-top: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* ── کد ─────────────────────────────────────────────────────────── */
.code {
    position: relative;
    margin: 0 0 14px;
}
.code:last-child { margin-bottom: 0; }
.code pre {
    margin: 0;
    padding: 13px 15px;
    padding-right: 46px; /* فیزیکی: محتوای pre همیشه LTR است */
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow-x: auto;
    direction: ltr;
    text-align: left;
    font-family: var(--font-mono);
    font-size: 12.5px;
    line-height: 1.65;
    white-space: pre-wrap;
    word-break: break-word;
    color: var(--text);
}
.code pre.tall { max-height: 420px; overflow-y: auto; }
.code .copy {
    position: absolute;
    top: 7px;
    right: 7px; /* فیزیکی، هم‌جهت با padding بالا */
    opacity: .55;
    transition: opacity .15s;
}
.code:hover .copy, .code .copy:focus-visible { opacity: 1; }

code.inline {
    font-family: var(--font-mono);
    font-size: .92em;
    background: var(--bg-soft);
    border: 1px solid var(--border);
    padding: 1px 6px;
    border-radius: 5px;
    direction: ltr;
    unicode-bidi: isolate;
    display: inline-block;
}

/* ── پیام ───────────────────────────────────────────────────────── */
.alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    border: 1px solid;
    font-size: 13.5px;
    margin-bottom: 18px;
}
.alert svg { width: 17px; height: 17px; flex: 0 0 17px; margin-top: 4px; }
.alert.err { background: var(--fail-soft); color: var(--fail); border-color: color-mix(in srgb, var(--fail) 35%, transparent); }
.alert.ok  { background: var(--ok-soft);   color: var(--ok);   border-color: color-mix(in srgb, var(--ok) 35%, transparent); }
@supports not (color: color-mix(in srgb, red, blue)) {
    .alert.err { border-color: var(--fail); }
    .alert.ok  { border-color: var(--ok); }
}

/* ── ورود ───────────────────────────────────────────────────────── */
.login-shell {
    min-height: 82vh;
    display: grid;
    place-items: center;
}
.login-card {
    width: 100%;
    max-width: 378px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    padding: 30px 28px 28px;
}
.login-card .brand-mark { width: 44px; height: 44px; flex-basis: 44px; margin-bottom: 18px; }
.login-card .brand-mark svg { width: 24px; height: 24px; }
.login-card h1 { margin: 0 0 4px; font-size: 19px; font-weight: 700; }
.login-card p.sub { margin: 0 0 22px; font-size: 13px; color: var(--text-soft); }
.login-card .btn { width: 100%; justify-content: center; margin-top: 4px; }

/* ── لاگ ────────────────────────────────────────────────────────── */
.log-level { font-size: 11.5px; font-weight: 600; }
.log-extra {
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--text-dim);
    direction: ltr;
    text-align: left; /* وگرنه JSON لاتین از سلول RTL راست‌چین می‌شود */
    unicode-bidi: isolate;
    word-break: break-all;
    margin-top: 3px;
    line-height: 1.6;
}
.log-time { white-space: nowrap; color: var(--text-dim); font-size: 12px; }
.filters { display: flex; gap: 6px; flex-wrap: wrap; }
.chip {
    padding: 4px 12px;
    border: 1px solid var(--border-2);
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-soft);
    font-family: inherit;
    font-size: 12.5px;
    cursor: pointer;
    transition: all .15s;
}
.chip:hover { border-color: var(--accent); color: var(--text); }
.chip[aria-pressed="true"] {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--accent-text);
    font-weight: 600;
}

/* ── سوییچ و کنترل‌های مدیریت کلید ───────────────────────────── */
.switch {
    display: flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    margin-bottom: 0;
}
.switch input[type=checkbox] {
    width: 17px;
    height: 17px;
    margin: 0;
    flex: 0 0 17px;
    accent-color: var(--accent);
    cursor: pointer;
}

.btn.ghost.danger { color: var(--fail); }
.btn.ghost.danger:hover {
    background: var(--fail-soft);
    border-color: var(--fail);
    color: var(--fail);
}

code.inline.secret {
    user-select: all;
    font-size: 12px;
    letter-spacing: .02em;
}

td.actions { white-space: nowrap; }
td.actions form { display: inline-block; margin: 0; vertical-align: middle; }
td.actions form + form { margin-inline-start: 5px; }

.empty {
    padding: 48px 20px;
    text-align: center;
    color: var(--text-dim);
}
.empty svg { width: 34px; height: 34px; opacity: .4; margin-bottom: 10px; }

.meta-row {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    font-size: 12.5px;
    color: var(--text-soft);
}

.sect-title {
    font-size: 13px;
    font-weight: 650;
    color: var(--text);
    margin: 22px 0 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sect-title:first-child { margin-top: 0; }
.sect-title .n {
    width: 20px; height: 20px;
    flex: 0 0 20px;
    display: grid; place-items: center;
    border-radius: 6px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 11.5px;
    font-weight: 700;
}

.foot {
    text-align: center;
    color: var(--text-dim);
    font-size: 12px;
    padding-top: 10px;
}

.foot.sign { padding-top: 5px; }

.foot a {
    color: var(--text-soft);
    text-decoration: none;
    border-bottom: 1px solid var(--border-2);
}

.foot a:hover { color: var(--accent); border-bottom-color: var(--accent); }

.foot .star { color: var(--warn); }

@media (max-width: 640px) {
    body { padding: 0 12px 56px; font-size: 14px; }
    th, td { padding: 10px 14px; }
    .card-head, .card-body, .tiles { padding-inline: 15px; }
    .topbar { padding: 16px 0 14px; }
    .brand-text p { white-space: normal; }
    td.name { white-space: normal; }
}

@media (prefers-reduced-motion: reduce) {
    * { transition: none !important; animation: none !important; }
}

@media print {
    .tabs, .topbar-actions, .copy, .btn { display: none !important; }
    .card { box-shadow: none; break-inside: avoid; }
}
</style>
</head>
<body>
<div class="wrap">

<?php
// ── آیکون‌های درون‌خطی ──────────────────────────────────────────────
$icon = static function (string $path, string $extra = ''): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ' . $extra . '>'
        . '<path d="' . $path . '"/></svg>';
};

$iconSat   = 'M12 2a10 10 0 0110 10M12 6a6 6 0 016 6M5 13l6 6M8.5 9.5l6 6M4 20l3-3';
$iconCopy  = 'M8 4v12a2 2 0 002 2h8M8 4H6a2 2 0 00-2 2v10M8 4h6l6 6v8a2 2 0 01-2 2h-2';
$iconOut   = 'M17 16l4-4m0 0l-4-4m4 4H7m6 8H5a2 2 0 01-2-2V6a2 2 0 012-2h8';
$iconWarn  = 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z';
$iconCheck = 'M22 11.08V12a10 10 0 11-5.93-9.14M22 4L12 14.01l-3-3';
$iconSun   = 'M12 3v1m0 16v1m9-10h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z';
$iconEmpty = 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4';
$iconNet   = 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
?>

<?php if (!$loggedIn) { ?>

    <div class="login-shell">
        <div class="login-card">
            <div class="brand-mark"><?= $icon($iconSat) ?></div>
            <h1>پنل رله</h1>
            <p class="sub">برای ادامه رمز پنل را وارد کنید.</p>

            <?php if ($error !== null) { ?>
                <div class="alert err"><?= $icon($iconWarn) ?><span><?= e($error) ?></span></div>
            <?php } ?>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="login">
                <div class="field">
                    <label for="password">رمز پنل</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" autofocus>
                </div>
                <button class="btn" type="submit">ورود</button>
            </form>
        </div>

        <p class="foot sign">
            <a href="https://github.com/tahazahed/php-relay-proxy" target="_blank" rel="noopener noreferrer">php-relay-proxy</a>
            · ساخته‌شده توسط <a href="https://github.com/tahazahed" target="_blank" rel="noopener noreferrer">Taha&nbsp;Zahed</a>
        </p>
    </div>

<?php } else { ?>

    <header class="topbar">
        <div class="brand">
            <div class="brand-mark"><?= $icon($iconSat) ?></div>
            <div class="brand-text">
                <h1>پنل رله</h1>
                <p class="mono"><?= e($relayBase) ?></p>
            </div>
        </div>
        <div class="topbar-actions">
            <button class="btn ghost icon" type="button" id="theme-toggle"
                    title="تغییر تم روشن/تاریک" aria-label="تغییر تم"><?= $icon($iconSun) ?></button>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="btn ghost small" type="submit"><?= $icon($iconOut) ?>خروج</button>
            </form>
        </div>
    </header>

    <?php if ($error !== null) { ?>
        <div class="alert err"><?= $icon($iconWarn) ?><span><?= e($error) ?></span></div>
    <?php } ?>
    <?php if ($notice !== null) { ?>
        <div class="alert ok"><?= $icon($iconCheck) ?><span><?= e($notice) ?></span></div>
    <?php } ?>

    <nav class="tabs">
        <?php foreach ($tabs as $key => [$label, $path]) { ?>
            <a href="?tab=<?= e($key) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>>
                <?= $icon($path) ?><?= e($label) ?>
            </a>
        <?php } ?>
    </nav>

    <?php if ($tab === 'health') { ?>

        <div class="card">
            <div class="card-head">
                <h2>سلامت نصب</h2>
                <div class="spacer"></div>
                <?php if (!$withNetwork) { ?>
                    <a class="btn ghost small" href="?tab=health&amp;net=1"><?= $icon($iconNet) ?>تست اتصال خروجی</a>
                <?php } else { ?>
                    <a class="btn ghost small" href="?tab=health">بدون تست شبکه</a>
                <?php } ?>
            </div>

            <div class="tiles">
                <div class="tile ok">
                    <span class="v"><?= $healthSummary['ok'] ?></span>
                    <span class="k">سالم</span>
                </div>
                <div class="tile warn">
                    <span class="v"><?= $healthSummary['warn'] ?></span>
                    <span class="k">هشدار</span>
                </div>
                <div class="tile fail">
                    <span class="v"><?= $healthSummary['fail'] ?></span>
                    <span class="k">مشکل جدی</span>
                </div>
                <div class="tile">
                    <span class="v"><?= e(PHP_VERSION) ?></span>
                    <span class="k">نسخه PHP</span>
                </div>
            </div>

            <?php if (!$withNetwork) { ?>
                <p class="hint" style="padding: 14px 20px 0; margin: 0;">
                    تست اتصال خروجی (DNS و دسترسی واقعی به اینترنت) چند ثانیه طول می‌کشد،
                    پس به‌صورت پیش‌فرض اجرا نمی‌شود.
                </p>
            <?php } ?>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 92px">وضعیت</th>
                            <th style="width: 210px">مورد</th>
                            <th>جزئیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $statusLabel = ['ok' => 'سالم', 'warn' => 'هشدار', 'fail' => 'مشکل'];

                    foreach ($healthChecks as $check) { ?>
                        <tr>
                            <td>
                                <span class="pill <?= e($check['status']) ?>">
                                    <?= e($statusLabel[$check['status']] ?? $check['status']) ?>
                                </span>
                            </td>
                            <td class="name"><?= e($check['name']) ?></td>
                            <td class="detail"><?= e($check['detail']) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php } elseif ($tab === 'keys') { ?>

        <?php if ($newKey !== null) { ?>
            <div class="card" style="border-color: var(--ok);">
                <div class="card-head" style="background: var(--ok-soft);">
                    <h2 style="color: var(--ok);">کلید تازه ساخته شد</h2>
                </div>
                <div class="card-body">
                    <p class="hint">
                        این مقدار را در کد سمت ایران بگذارید. هر وقت خواستید
                        دوباره از همین صفحه قابل دیدن است.
                    </p>
                    <div class="code">
                        <pre><?= e($newKey['secret']) ?></pre>
                        <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                    </div>
                    <p class="hint" style="margin: 12px 0 0;">آدرس آماده:</p>
                    <div class="code">
                        <pre><?= e($relayBase) ?>/r/<?= e($newKey['secret']) ?>/https://api.telegram.org/bot&lt;TOKEN&gt;/getMe</pre>
                        <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                    </div>
                </div>
            </div>
        <?php } ?>

        <div class="card">
            <div class="card-head">
                <h2>کلیدهای دسترسی</h2>
                <div class="spacer"></div>
                <span class="pill mute plain"><?= count($settings->keys()) ?> کلید</span>
            </div>
            <div class="card-body">
                <p class="hint">
                    هر کلید یک دسترسی مستقل است. می‌توانید برای هر سرور یا هر
                    برنامه یک کلید جدا بسازید و بعداً فقط همان را غیرفعال یا حذف
                    کنید، بدون اینکه بقیه از کار بیفتند.
                </p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="key_create">
                    <div class="row">
                        <div class="field">
                            <label for="label">نام کلید</label>
                            <input id="label" type="text" name="label" required maxlength="40"
                                   placeholder="مثلاً: سرور ایران">
                        </div>
                        <div class="field">
                            <label for="note">توضیح (اختیاری)</label>
                            <input id="note" type="text" name="note" maxlength="120"
                                   placeholder="مثلاً: بات املاک">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn" type="submit"<?= $settings->writable() ? '' : ' disabled' ?>>
                            <?= $icon('M12 4v16m8-8H4') ?>ساخت کلید جدید
                        </button>
                        <?php if (!$settings->writable()) { ?>
                            <span class="pill fail">فایل تنظیمات قابل نوشتن نیست</span>
                        <?php } ?>
                    </div>
                </form>
            </div>

            <?php if ($settings->keys() === []) { ?>
                <div class="empty">
                    <?= $icon($iconEmpty) ?>
                    <div>هیچ کلیدی تعریف نشده — تا وقتی کلیدی نباشد، رله هیچ درخواستی را قبول نمی‌کند.</div>
                </div>
            <?php } else { ?>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 88px">وضعیت</th>
                                <th style="width: 170px">نام</th>
                                <th>کلید</th>
                                <th style="width: 110px">ساخته شده</th>
                                <th style="width: 165px"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($settings->keys() as $key) {
                            $secret = (string) $key['secret'];
                            $masked = substr($secret, 0, 6) . str_repeat('.', 10) . substr($secret, -4);
                            ?>
                            <tr>
                                <td>
                                    <span class="pill <?= $key['enabled'] ? 'ok' : 'mute' ?>">
                                        <?= $key['enabled'] ? 'فعال' : 'غیرفعال' ?>
                                    </span>
                                </td>
                                <td class="name">
                                    <?= e((string) $key['label']) ?>
                                    <?php if (($key['note'] ?? '') !== '') { ?>
                                        <div class="hint" style="margin: 2px 0 0; font-size: 12px;"><?= e((string) $key['note']) ?></div>
                                    <?php } ?>
                                </td>
                                <td>
                                    <code class="inline secret" data-secret="<?= e($secret) ?>"
                                          data-masked="<?= e($masked) ?>"><?= e($masked) ?></code>
                                    <button class="btn ghost small reveal" type="button" style="margin-inline-start: 6px;">نمایش</button>
                                    <button class="btn ghost small copy-secret" type="button">کپی</button>
                                </td>
                                <td class="log-time" title="<?= e((string) $key['created_at']) ?>">
                                    <?= e(relay_ago((string) $key['created_at'])) ?>
                                </td>
                                <td class="actions">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="key_toggle">
                                        <input type="hidden" name="id" value="<?= e((string) $key['id']) ?>">
                                        <button class="btn ghost small" type="submit"><?= $key['enabled'] ? 'غیرفعال' : 'فعال' ?></button>
                                    </form>
                                    <form method="post" data-confirm="کلید «<?= e((string) $key['label']) ?>» حذف شود؟ هر برنامه‌ای که از آن استفاده می‌کند بلافاصله از کار می‌افتد.">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="key_delete">
                                        <input type="hidden" name="id" value="<?= e((string) $key['id']) ?>">
                                        <button class="btn ghost small danger" type="submit">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>

    <?php } elseif ($tab === 'targets') {
        $restrict = $settings->restrictTargets();
        ?>

        <div class="card">
            <div class="card-head">
                <h2>مقصدهای مجاز</h2>
                <div class="spacer"></div>
                <span class="pill <?= $restrict ? 'ok' : 'fail' ?>">
                    <?= $restrict ? 'محدودیت فعال — ' . count($settings->hosts()) . ' دامنه' : 'بدون محدودیت' ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (!$restrict) { ?>
                    <div class="alert err">
                        <?= $icon($iconWarn) ?>
                        <span>
                            <strong>محدودیت خاموش است.</strong>
                            هر کسی که کلید داشته باشد می‌تواند از این هاست به
                            «هر» آدرسی در اینترنت درخواست بفرستد. اگر کلید لو برود،
                            هاست شما برای سوءاستفاده استفاده می‌شود و معمولاً
                            سرویس‌دهنده حساب را معلق می‌کند.
                        </span>
                    </div>
                <?php } ?>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="targets_save">

                    <div class="field">
                        <label class="switch">
                            <input type="checkbox" name="restrict" value="1" <?= $restrict ? 'checked' : '' ?>>
                            فقط به دامنه‌های زیر اجازه بده
                        </label>
                        <p class="hint" style="margin: 8px 0 0;">
                            اگر تیک را بردارید، رله به هر دامنه‌ای درخواست می‌فرستد.
                        </p>
                    </div>

                    <div class="field">
                        <label for="hosts">دامنه‌ها — هر خط یکی</label>
                        <textarea id="hosts" name="hosts" rows="12" spellcheck="false"
                                  placeholder="api.telegram.org"><?= e(implode("\n", $settings->hosts())) ?></textarea>
                        <p class="hint" style="margin: 8px 0 0;">
                            <code class="inline">api.openai.com</code> فقط همان دامنه &middot;
                            <code class="inline">*.telegram.org</code> خودش و همه زیردامنه‌ها &middot;
                            آدرس کامل هم بچسبانید، خودش به دامنه تبدیل می‌شود.
                            <br>
                            شبکه‌های داخلی (<code class="inline">127.0.0.1</code>،
                            <code class="inline">10.x</code>،
                            <code class="inline">169.254.169.254</code>) مستقل از این لیست همیشه مسدودند.
                        </p>
                    </div>

                    <div class="form-actions">
                        <button class="btn" type="submit"<?= $settings->writable() ? '' : ' disabled' ?>>
                            <?= $icon($iconCheck) ?>ذخیره
                        </button>
                        <?php if (!$settings->writable()) { ?>
                            <span class="pill fail">فایل تنظیمات قابل نوشتن نیست</span>
                        <?php } ?>
                        <span class="hint" style="margin: 0;">
                            آخرین تغییر: <?= e(relay_ago($settings->updatedAt())) ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($restrict && $settings->hosts() !== []) { ?>
            <div class="card">
                <div class="card-head"><h2>لیست فعلی</h2></div>
                <div class="card-body">
                    <div class="filters">
                        <?php foreach ($settings->hosts() as $host) { ?>
                            <span class="pill mute plain mono"><?= e($host) ?></span>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>

    <?php } elseif ($tab === 'security') { ?>

        <div class="card">
            <div class="card-head">
                <h2>رمز ورود به پنل</h2>
                <div class="spacer"></div>
                <?php if ($settings->hasPanelPassword()) { ?>
                    <span class="pill ok">از پنل تنظیم شده</span>
                <?php } else { ?>
                    <span class="pill mute plain">از config.php خوانده می‌شود</span>
                <?php } ?>
            </div>
            <div class="card-body">
                <p class="hint">
                    این رمز فقط برای ورود به همین پنل است و هیچ ربطی به
                    کلیدهای دسترسی رله ندارد. بعد از تغییر، رمز به‌صورت
                    هش‌شده در <code class="inline">settings.json</code> ذخیره
                    می‌شود و مقدار <code class="inline">panel.password</code>
                    در <code class="inline">config.php</code> دیگر کار نمی‌کند.
                </p>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="password_change">

                    <div class="field">
                        <label for="current">رمز فعلی</label>
                        <input id="current" type="password" name="current" required
                               autocomplete="current-password">
                    </div>

                    <div class="row">
                        <div class="field">
                            <label for="new">رمز جدید</label>
                            <input id="new" type="password" name="new" required
                                   minlength="<?= \Relay\Settings::MIN_PANEL_PASSWORD ?>"
                                   autocomplete="new-password">
                        </div>
                        <div class="field">
                            <label for="confirm">تکرار رمز جدید</label>
                            <input id="confirm" type="password" name="confirm" required
                                   minlength="<?= \Relay\Settings::MIN_PANEL_PASSWORD ?>"
                                   autocomplete="new-password">
                        </div>
                    </div>

                    <p class="hint" style="margin: 0 0 4px;">
                        حداقل <?= \Relay\Settings::MIN_PANEL_PASSWORD ?> کاراکتر.
                        <?php if ($settings->panelPasswordChangedAt() !== '') { ?>
                            &middot; آخرین تغییر: <?= e(relay_ago($settings->panelPasswordChangedAt())) ?>
                        <?php } ?>
                    </p>

                    <div class="form-actions">
                        <button class="btn" type="submit"<?= $settings->writable() ? '' : ' disabled' ?>>
                            <?= $icon($iconCheck) ?>تغییر رمز
                        </button>
                        <?php if (!$settings->writable()) { ?>
                            <span class="pill fail">فایل تنظیمات قابل نوشتن نیست</span>
                        <?php } ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>اگر رمز را فراموش کردید</h2></div>
            <div class="card-body">
                <p class="hint">
                    از File Manager هاست فایل
                    <code class="inline">settings.json</code> را باز کنید و مقدار
                    <code class="inline">password_hash</code> را خالی کنید:
                </p>
                <div class="code">
                    <pre>"panel": {
    "password_hash": ""
}</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>
                <p class="hint" style="margin-top: 12px;">
                    بعد از این، دوباره رمزی که در <code class="inline">config.php</code>
                    بخش <code class="inline">panel</code> هست معتبر می‌شود.
                    برای ساخت هش جدید از خط فرمان:
                </p>
                <div class="code">
                    <pre>php -r "echo password_hash('رمز-جدید', PASSWORD_DEFAULT);"</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>محدود کردن دسترسی به پنل</h2></div>
            <div class="card-body">
                <p class="hint">
                    اگر IP ثابت دارید، می‌توانید پنل را فقط برای همان باز بگذارید.
                    در <code class="inline">config.php</code>:
                </p>
                <div class="code">
                    <pre>'panel' =&gt; [
    'ip_allowlist' =&gt; ['<?= e(Util::clientIp()) ?>'],
],</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>
                <p class="hint" style="margin-top: 12px;">
                    IP فعلی شما: <code class="inline"><?= e(Util::clientIp()) ?></code>
                    &middot; اگر IP شما متغیر است این کار را نکنید، خودتان را بیرون می‌اندازید.
                    برای خاموش کردن کامل پنل، <code class="inline">panel.enabled</code> را
                    <code class="inline">false</code> کنید.
                </p>
            </div>
        </div>

    <?php } elseif ($tab === 'test') { ?>

        <div class="card">
            <div class="card-head"><h2>تست درخواست</h2></div>
            <div class="card-body">
                <p class="hint">
                    در حالت «از طریق رله»، پنل یک درخواست واقعی به آدرس رله همین هاست می‌زند —
                    یعنی کل مسیر (مسیریابی، کلید، محدودیت نرخ و مقصد) تست می‌شود.
                    حالت «مستقیم» بدون رله می‌رود و برای مقایسه خوب است.
                </p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="test">

                    <div class="field">
                        <label for="target">آدرس مقصد</label>
                        <input id="target" class="mono" type="text" name="target"
                               value="<?= e((string) ($_POST['target'] ?? 'https://api.telegram.org/')) ?>"
                               placeholder="https://api.telegram.org/bot&lt;TOKEN&gt;/getMe">
                    </div>

                    <div class="row">
                        <div class="field">
                            <label for="method">متد</label>
                            <select id="method" name="method">
                                <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) { ?>
                                    <option <?= ($_POST['method'] ?? '') === $method ? 'selected' : '' ?>><?= e($method) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="key">کلید</label>
                            <select id="key" name="key">
                                <?php foreach ($keyLabels as $label) { ?>
                                    <option value="<?= e((string) $label) ?>" <?= ($_POST['key'] ?? '') === (string) $label ? 'selected' : '' ?>>
                                        <?= e((string) $label) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="mode">مسیر</label>
                            <select id="mode" name="mode">
                                <option value="relay" <?= ($_POST['mode'] ?? 'relay') === 'relay' ? 'selected' : '' ?>>از طریق رله</option>
                                <option value="direct" <?= ($_POST['mode'] ?? '') === 'direct' ? 'selected' : '' ?>>مستقیم از هاست</option>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label for="body">بدنه درخواست (اختیاری)</label>
                        <textarea id="body" name="body" placeholder='{"chat_id":123,"text":"سلام"}'><?= e((string) ($_POST['body'] ?? '')) ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button class="btn" type="submit"><?= $icon('M13 10V3L4 14h7v7l9-11h-7z') ?>ارسال</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($testResult !== null) { ?>
            <div class="card">
                <div class="card-head">
                    <h2>نتیجه</h2>
                    <div class="spacer"></div>
                    <?php if (isset($testResult['status'])) {
                        $tone = relay_status_tone((int) $testResult['status']); ?>
                        <span class="pill <?= e($tone) ?>">HTTP <?= (int) $testResult['status'] ?></span>
                        <span class="pill mute plain num"><?= (int) ($testResult['duration'] ?? 0) ?> ms</span>
                        <span class="pill mute plain"><?= e(Util::humanBytes((int) ($testResult['size'] ?? 0))) ?></span>
                    <?php } ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($testResult['message'])) { ?>
                        <div class="alert err"><?= $icon($iconWarn) ?><span><?= e((string) $testResult['message']) ?></span></div>
                    <?php } ?>

                    <?php if (isset($testResult['url'])) { ?>
                        <div class="sect-title"><span class="n">۱</span>آدرس درخواست</div>
                        <div class="code">
                            <pre><?= e((string) $testResult['url']) ?></pre>
                            <button class="btn ghost icon copy" type="button" data-copy
                                    title="کپی"><?= $icon($iconCopy) ?></button>
                        </div>
                    <?php } ?>

                    <?php if (isset($testResult['status'])) { ?>
                        <div class="sect-title"><span class="n">۲</span>هدرهای پاسخ</div>
                        <div class="code">
                            <pre class="tall"><?= e((string) ($testResult['headers'] ?? '')) ?></pre>
                            <button class="btn ghost icon copy" type="button" data-copy
                                    title="کپی"><?= $icon($iconCopy) ?></button>
                        </div>

                        <div class="sect-title"><span class="n">۳</span>بدنه پاسخ</div>
                        <div class="code">
                            <pre class="tall"><?= e((string) ($testResult['body'] ?? '')) ?></pre>
                            <button class="btn ghost icon copy" type="button" data-copy
                                    title="کپی"><?= $icon($iconCopy) ?></button>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>

    <?php } elseif ($tab === 'logs') { ?>

        <div class="card">
            <div class="card-head">
                <h2>لاگ‌ها</h2>
                <div class="spacer"></div>
                <?php if ($logFiles !== []) { ?>
                    <div class="filters" id="log-filters">
                        <button class="chip" type="button" data-level="all" aria-pressed="true">همه</button>
                        <button class="chip" type="button" data-level="warning" aria-pressed="false">هشدار</button>
                        <button class="chip" type="button" data-level="error" aria-pressed="false">خطا</button>
                    </div>
                    <form method="get">
                        <input type="hidden" name="tab" value="logs">
                        <select name="log" onchange="this.form.submit()" aria-label="انتخاب فایل لاگ">
                            <?php foreach ($logFiles as $file) { ?>
                                <option value="<?= e($file) ?>" <?= $file === $selectedLog ? 'selected' : '' ?>>
                                    <?= e(basename($file)) ?> — <?= e(Util::humanBytes((int) @filesize($file))) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </form>
                <?php } ?>
            </div>

            <?php if ($logFiles === [] || $logLines === []) { ?>
                <div class="empty">
                    <?= $icon($iconEmpty) ?>
                    <div><?= $logFiles === [] ? 'هنوز لاگی ثبت نشده است.' : 'این فایل لاگ خالی است.' ?></div>
                </div>
            <?php } else { ?>
                <div class="table-scroll">
                    <table id="log-table">
                        <thead>
                            <tr>
                                <th style="width: 118px">زمان</th>
                                <th style="width: 78px">سطح</th>
                                <th>پیام</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $levelTone  = ['error' => 'fail', 'warning' => 'warn', 'info' => 'info', 'debug' => 'mute'];
                        $levelLabel = ['error' => 'خطا', 'warning' => 'هشدار', 'info' => 'اطلاع', 'debug' => 'دیباگ'];

                        foreach ($logLines as $line) {
                            $level = (string) ($line['level'] ?? 'info');
                            $time  = (string) ($line['time'] ?? '');
                            $extra = $line;
                            unset($extra['time'], $extra['level'], $extra['message']);
                            ?>
                            <tr data-level="<?= e($level) ?>">
                                <td class="log-time" title="<?= e($time) ?>"><?= e(relay_ago($time)) ?></td>
                                <td>
                                    <span class="pill <?= e($levelTone[$level] ?? 'mute') ?> log-level">
                                        <?= e($levelLabel[$level] ?? $level) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e((string) ($line['message'] ?? '')) ?>
                                    <?php if ($extra !== []) { ?>
                                        <div class="log-extra"><?= e((string) json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-head" style="border-bottom: 0; border-top: 1px solid var(--border);">
                    <span class="hint" style="margin: 0;">
                        نمایش <?= count($logLines) ?> رکورد آخر از
                        <code class="inline"><?= e(basename($selectedLog)) ?></code>
                        <?php
                        $totalRecords = $logger->countRecords($selectedLog);
                        $cap          = $logger->maxRecords();
                        ?>
                        &middot; <?= $totalRecords ?> رکورد در فایل
                        <?php if ($cap > 0) { ?>
                            &middot; سقف <?= $cap ?> رکورد
                            <span class="pill mute plain" style="margin-inline-start: 4px;">
                                خودکار بریده می‌شود
                            </span>
                        <?php } else { ?>
                            &middot; <span class="pill warn">بدون سقف</span>
                        <?php } ?>
                    </span>
                </div>
            <?php } ?>
        </div>

    <?php } else {
        $exampleKey = '&lt;KEY&gt;';
        ?>

        <div class="card">
            <div class="card-head"><h2>راهنمای استفاده</h2></div>
            <div class="card-body">
                <p class="hint">
                    جای <code class="inline">&lt;KEY&gt;</code> کلید دسترسی خود را بگذارید.
                    کلیدهای تعریف‌شده: <?php foreach ($keyLabels as $i => $label) {
                        echo ($i ? ' · ' : '') . '<code class="inline">' . e((string) $label) . '</code>';
                    } ?>
                </p>

                <div class="sect-title"><span class="n">۱</span>مسیر با کلید — ساده‌ترین حالت</div>
                <div class="code">
                    <pre><?= e($relayBase) ?>/r/&lt;KEY&gt;/https://api.telegram.org/bot&lt;TOKEN&gt;/getMe</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>

                <div class="sect-title"><span class="n">۲</span>کلید در هدر</div>
                <div class="code">
                    <pre>curl "<?= e($relayBase) ?>/r/https://api.telegram.org/bot&lt;TOKEN&gt;/getMe" \
  -H "<?= e($config->str('auth.header', 'X-Relay-Key')) ?>: &lt;KEY&gt;"</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>

                <div class="sect-title"><span class="n">۳</span>پارامتر کوئری</div>
                <div class="code">
                    <pre><?= e($relayBase) ?>/?<?= e($config->str('target.query_param', 'relay_url')) ?>=https%3A%2F%2Fapi.telegram.org%2Fbot&lt;TOKEN&gt;%2FgetMe&amp;<?= e($config->str('auth.query_param', 'relay_key')) ?>=&lt;KEY&gt;</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>

                <div class="sect-title"><span class="n">۴</span>تنظیم کتابخانه بات تلگرام</div>
                <p class="hint">در کتابخانه‌هایی که امکان تغییر آدرس پایه API را می‌دهند، فقط همین را بگذارید:</p>
                <div class="code">
                    <pre><?= e($relayBase) ?>/r/&lt;KEY&gt;/https://api.telegram.org</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>

                <div class="sect-title"><span class="n">۵</span>دانلود فایل از تلگرام</div>
                <p class="hint">مسیر فایل‌ها با مسیر API فرق دارد:</p>
                <div class="code">
                    <pre><?= e($relayBase) ?>/r/&lt;KEY&gt;/https://api.telegram.org/file/bot&lt;TOKEN&gt;/&lt;file_path&gt;</pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>
            </div>
        </div>

<div class="card">
            <div class="card-head"><h2>ساخت کلید جدید</h2></div>
            <div class="card-body">
                <p class="hint">
                    این مقدار تصادفی را در <code class="inline">config.php</code> بخش
                    <code class="inline">auth.keys</code> بگذارید. هر بار باز کردن این صفحه
                    مقدار جدیدی می‌سازد.
                </p>
                <div class="code">
                    <pre><?= e(bin2hex(random_bytes(24))) ?></pre>
                    <button class="btn ghost icon copy" type="button" data-copy title="کپی"><?= $icon($iconCopy) ?></button>
                </div>
            </div>
        </div>

    <?php } ?>

    <p class="foot">
        php-relay ·
        <?= e(gmdate('Y-m-d H:i')) ?> UTC ·
        IP شما: <span class="mono"><?= e(Util::clientIp()) ?></span>
    </p>

    <p class="foot sign">
        ساخته‌شده توسط <a href="https://github.com/tahazahed" target="_blank" rel="noopener noreferrer">Taha&nbsp;Zahed</a>
        · اگر به کارتان آمد، در
        <a href="https://github.com/tahazahed/php-relay-proxy" target="_blank" rel="noopener noreferrer">گیت‌هاب</a>
        یک <span class="star">★</span> بدهید
    </p>

<?php } ?>

</div>

<script>
(function () {
    'use strict';

    // ── تم روشن/تاریک ───────────────────────────────────────────────
    var root = document.documentElement;

    try {
        var saved = localStorage.getItem('relay-theme');
        if (saved === 'dark' || saved === 'light') { root.setAttribute('data-theme', saved); }
    } catch (e) { /* حالت ناشناس مرورگر — بی‌اهمیت */ }

    var toggle = document.getElementById('theme-toggle');

    if (toggle) {
        toggle.addEventListener('click', function () {
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var current = root.getAttribute('data-theme') || (prefersDark ? 'dark' : 'light');
            var next = current === 'dark' ? 'light' : 'dark';

            root.setAttribute('data-theme', next);

            try { localStorage.setItem('relay-theme', next); } catch (e) {}
        });
    }

    // ── کپی کردن متن ───────────────────────────────────────────────
    function copyText(text, button, resetLabel) {
        var flash = function () {
            if (resetLabel) {
                button.textContent = 'کپی شد';
                setTimeout(function () { button.textContent = resetLabel; }, 1200);
                return;
            }

            var old = button.getAttribute('title');
            button.setAttribute('title', 'کپی شد');
            button.style.opacity = '1';
            setTimeout(function () {
                button.setAttribute('title', old || 'کپی');
                button.style.opacity = '';
            }, 1200);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(flash, function () {});
            return;
        }

        // جایگزین برای مرورگرهای قدیمی یا اتصال غیر HTTPS
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); flash(); } catch (e) {}
        document.body.removeChild(area);
    }

    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var pre = button.parentElement.querySelector('pre');
            if (pre) { copyText(pre.textContent, button, null); }
        });
    });

    // ── نمایش و کپی کلید دسترسی ────────────────────────────────────
    document.querySelectorAll('.reveal').forEach(function (button) {
        button.addEventListener('click', function () {
            var code = button.parentElement.querySelector('.secret');
            if (!code) { return; }

            var hidden = code.textContent === code.getAttribute('data-masked');
            code.textContent = hidden ? code.getAttribute('data-secret') : code.getAttribute('data-masked');
            button.textContent = hidden ? 'پنهان' : 'نمایش';
        });
    });

    document.querySelectorAll('.copy-secret').forEach(function (button) {
        button.addEventListener('click', function () {
            var code = button.parentElement.querySelector('.secret');
            if (!code) { return; }

            copyText(code.getAttribute('data-secret'), button, 'کپی');
        });
    });

    // ── تایید قبل از حذف ───────────────────────────────────────────
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // ── فیلتر سطح لاگ ──────────────────────────────────────────────
    var filters = document.getElementById('log-filters');
    var table = document.getElementById('log-table');

    if (filters && table) {
        filters.addEventListener('click', function (event) {
            var chip = event.target.closest('.chip');
            if (!chip) { return; }

            filters.querySelectorAll('.chip').forEach(function (other) {
                other.setAttribute('aria-pressed', String(other === chip));
            });

            var want = chip.getAttribute('data-level');

            table.querySelectorAll('tbody tr').forEach(function (row) {
                var level = row.getAttribute('data-level');
                var show = want === 'all'
                    || level === want
                    || (want === 'warning' && level === 'error');
                row.style.display = show ? '' : 'none';
            });
        });
    }
})();
</script>
</body>
</html>
