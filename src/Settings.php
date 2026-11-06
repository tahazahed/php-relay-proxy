<?php

namespace Relay;

/**
 * تنظیمات قابل‌ویرایش از پنل (کلیدها و مقصدها).
 *
 * چرا جدا از config.php؟ چون config.php دست‌نویس است و بازنویسی برنامه‌ای
 * آن ریسک خراب شدن فایل را دارد. این کلاس یک فایل JSON کنار config.php
 * نگه می‌دارد که فقط پنل می‌نویسدش.
 *
 * اولویت: اگر settings.json وجود داشته باشد، کلیدها و لیست مقصد را
 * «کاملاً» از آن می‌گیریم و مقادیر config.php نادیده گرفته می‌شوند. این‌طور
 * رفتار قابل پیش‌بینی است و کلیدی که از پنل حذف شده واقعاً حذف می‌شود.
 * بار اول، مقادیر config.php به settings.json منتقل می‌شوند.
 */
class Settings
{
    public const VERSION = 1;

    /** حداقل طول رمز پنل */
    public const MIN_PANEL_PASSWORD = 8;

    /** @var string */
    private $path;

    /** @var array<string,mixed> */
    private $data;

    /** @var string|null دلیل اینکه فایل قابل استفاده نبود */
    private $loadError = null;

    private function __construct(string $path, array $data)
    {
        $this->path = $path;
        $this->data = $data;
    }

    /**
     * مسیر فایل تنظیمات: کنار config.php.
     */
    public static function pathFor(string $configPath): string
    {
        return dirname($configPath) . '/settings.json';
    }

    /**
     * فایل را می‌خواند. اگر نبود، از روی config.php ساخته می‌شود.
     */
    public static function load(string $path, Config $config): self
    {
        if (!is_file($path)) {
            $settings = new self($path, self::seedFrom($config));
            $settings->save(); // اگر ننوشت هم مهم نیست؛ در حافظه کار می‌کند

            return $settings;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            $settings            = new self($path, self::seedFrom($config));
            $settings->loadError = 'فایل settings.json قابل خواندن نبود.';

            return $settings;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['keys'])) {
            $settings            = new self($path, self::seedFrom($config));
            $settings->loadError = 'محتوای settings.json معتبر نبود؛ از config.php استفاده شد.';

            return $settings;
        }

        return new self($path, self::normalize($decoded));
    }

    /**
     * ساخت ساختار اولیه از روی config.php.
     *
     * @return array<string,mixed>
     */
    private static function seedFrom(Config $config): array
    {
        $keys = [];

        foreach ($config->arr('auth.keys') as $label => $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }

            $keys[] = [
                'id'         => self::newId(),
                'label'      => (string) $label,
                'secret'     => $secret,
                'enabled'    => true,
                'created_at' => gmdate('c'),
                'note'       => 'منتقل‌شده از config.php',
            ];
        }

        $hosts = [];

        foreach ($config->arr('target.host_allowlist') as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower(trim($host));
            }
        }

        return self::normalize([
            'version' => self::VERSION,
            'keys'    => $keys,
            'targets' => [
                'restrict' => $hosts !== [],
                'hosts'    => $hosts,
            ],
            // خالی یعنی «از config.php بخوان»
            'panel' => ['password_hash' => ''],
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalize(array $data): array
    {
        $keys = [];

        foreach ($data['keys'] ?? [] as $key) {
            if (!is_array($key) || empty($key['secret']) || !is_string($key['secret'])) {
                continue;
            }

            $keys[] = [
                'id'         => isset($key['id']) && is_string($key['id']) ? $key['id'] : self::newId(),
                'label'      => self::cleanLabel((string) ($key['label'] ?? 'بدون نام')),
                'secret'     => $key['secret'],
                'enabled'    => !isset($key['enabled']) || (bool) $key['enabled'],
                'created_at' => isset($key['created_at']) && is_string($key['created_at'])
                    ? $key['created_at']
                    : gmdate('c'),
                'note'       => isset($key['note']) && is_string($key['note']) ? $key['note'] : '',
            ];
        }

        $targets = is_array($data['targets'] ?? null) ? $data['targets'] : [];
        $hosts   = [];

        foreach ($targets['hosts'] ?? [] as $host) {
            $clean = self::cleanHost((string) $host);

            if ($clean !== null && !in_array($clean, $hosts, true)) {
                $hosts[] = $clean;
            }
        }

        $panel = is_array($data['panel'] ?? null) ? $data['panel'] : [];
        $hash  = isset($panel['password_hash']) && is_string($panel['password_hash'])
            ? $panel['password_hash']
            : '';

        return [
            'version'    => self::VERSION,
            'updated_at' => isset($data['updated_at']) && is_string($data['updated_at'])
                ? $data['updated_at']
                : gmdate('c'),
            'keys'    => $keys,
            'targets' => [
                'restrict' => !isset($targets['restrict']) || (bool) $targets['restrict'],
                'hosts'    => $hosts,
            ],
            'panel' => [
                'password_hash'      => $hash,
                'password_changed_at' => isset($panel['password_changed_at']) && is_string($panel['password_changed_at'])
                    ? $panel['password_changed_at']
                    : '',
            ],
        ];
    }

    /**
     * اعمال روی Config — جایگزین کامل کلیدها و لیست مقصد.
     */
    public function applyTo(Config $config): void
    {
        $keys = [];
        $seen = [];

        foreach ($this->data['keys'] as $key) {
            if (!$key['enabled']) {
                continue;
            }

            // برچسب تکراری نباید کلید قبلی را بپوشاند
            $label = $key['label'];
            $suffix = 1;

            while (isset($seen[$label])) {
                $label = $key['label'] . ' (' . (++$suffix) . ')';
            }

            $seen[$label]  = true;
            $keys[$label]  = $key['secret'];
        }

        $config->set('auth.keys', $keys);

        $config->set(
            'target.host_allowlist',
            $this->data['targets']['restrict'] ? $this->data['targets']['hosts'] : []
        );

        // رمز پنل اگر از پنل عوض شده باشد، بر config.php اولویت دارد.
        // مقدار متن‌ساده‌ی config.php هم خنثی می‌شود تا دو رمز همزمان کار نکنند.
        if ($this->data['panel']['password_hash'] !== '') {
            $config->set('panel.password_hash', $this->data['panel']['password_hash']);
            $config->set('panel.password', '');
        }
    }

    /**
     * رمز پنل از پنل عوض شده است؟
     */
    public function hasPanelPassword(): bool
    {
        return $this->data['panel']['password_hash'] !== '';
    }

    public function panelPasswordChangedAt(): string
    {
        return (string) $this->data['panel']['password_changed_at'];
    }

    /**
     * تغییر رمز پنل.
     *
     * @throws RelayException اگر رمز کوتاه باشد
     */
    public function setPanelPassword(string $plain): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);

        if ($length < self::MIN_PANEL_PASSWORD) {
            throw RelayException::badRequest(
                'weak_password',
                'رمز باید حداقل ' . self::MIN_PANEL_PASSWORD . ' کاراکتر باشد.'
            );
        }

        if (strlen($plain) > 4096) {
            throw RelayException::badRequest('password_too_long', 'رمز خیلی بلند است.');
        }

        $hash = password_hash($plain, PASSWORD_DEFAULT);

        if (!is_string($hash) || $hash === '') {
            throw new RelayException(500, 'hash_failed', 'ساخت هش رمز ناموفق بود.');
        }

        $this->data['panel']['password_hash']       = $hash;
        $this->data['panel']['password_changed_at'] = gmdate('c');

        $this->save();
    }

    // ── خواندن ──────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function keys(): array
    {
        return $this->data['keys'];
    }

    public function restrictTargets(): bool
    {
        return (bool) $this->data['targets']['restrict'];
    }

    /** @return array<int,string> */
    public function hosts(): array
    {
        return $this->data['targets']['hosts'];
    }

    public function path(): string
    {
        return $this->path;
    }

    public function loadError(): ?string
    {
        return $this->loadError;
    }

    public function updatedAt(): string
    {
        return (string) $this->data['updated_at'];
    }

    public function writable(): bool
    {
        if (is_file($this->path)) {
            return is_writable($this->path);
        }

        return is_dir(dirname($this->path)) && is_writable(dirname($this->path));
    }

    // ── نوشتن ───────────────────────────────────────────────────────

    /**
     * ساخت کلید تازه. مقدار کلید برگردانده می‌شود.
     *
     * @throws RelayException اگر برچسب نامعتبر باشد
     */
    public function createKey(string $label, string $note = ''): array
    {
        $label = self::cleanLabel($label);

        if ($label === '') {
            throw RelayException::badRequest('invalid_label', 'نام کلید نمی‌تواند خالی باشد.');
        }

        if (count($this->data['keys']) >= 50) {
            throw RelayException::badRequest('too_many_keys', 'حداکثر ۵۰ کلید مجاز است.');
        }

        $key = [
            'id'         => self::newId(),
            'label'      => $label,
            'secret'     => bin2hex(random_bytes(24)),
            'enabled'    => true,
            'created_at' => gmdate('c'),
            'note'       => self::cleanLabel($note, 120),
        ];

        $this->data['keys'][] = $key;
        $this->save();

        return $key;
    }

    public function deleteKey(string $id): bool
    {
        $before = count($this->data['keys']);

        $this->data['keys'] = array_values(array_filter(
            $this->data['keys'],
            static function (array $key) use ($id): bool {
                return $key['id'] !== $id;
            }
        ));

        if (count($this->data['keys']) === $before) {
            return false;
        }

        $this->save();

        return true;
    }

    public function toggleKey(string $id): ?bool
    {
        foreach ($this->data['keys'] as $index => $key) {
            if ($key['id'] !== $id) {
                continue;
            }

            $this->data['keys'][$index]['enabled'] = !$key['enabled'];
            $this->save();

            return $this->data['keys'][$index]['enabled'];
        }

        return null;
    }

    public function findKey(string $id): ?array
    {
        foreach ($this->data['keys'] as $key) {
            if ($key['id'] === $id) {
                return $key;
            }
        }

        return null;
    }

    /**
     * ذخیره تنظیمات مقصدها.
     *
     * @param array<int,string> $hosts
     * @return array{saved:array<int,string>,rejected:array<int,string>}
     */
    public function saveTargets(bool $restrict, array $hosts): array
    {
        $clean    = [];
        $rejected = [];

        foreach ($hosts as $host) {
            $host = trim((string) $host);

            if ($host === '') {
                continue;
            }

            $normalized = self::cleanHost($host);

            if ($normalized === null) {
                $rejected[] = $host;
                continue;
            }

            if (!in_array($normalized, $clean, true)) {
                $clean[] = $normalized;
            }
        }

        $this->data['targets'] = ['restrict' => $restrict, 'hosts' => $clean];
        $this->save();

        return ['saved' => $clean, 'rejected' => $rejected];
    }

    /**
     * نوشتن اتمی: اول فایل موقت، بعد rename.
     */
    private function save(): bool
    {
        $this->data['updated_at'] = gmdate('c');
        $this->data['version']    = self::VERSION;

        $json = json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return false;
        }

        $dir = dirname($this->path);

        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $temp = $this->path . '.tmp' . Util::randomId(4);

        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            return false;
        }

        @chmod($temp, 0600);

        if (!@rename($temp, $this->path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    // ── اعتبارسنجی ──────────────────────────────────────────────────

    private static function newId(): string
    {
        return 'k_' . Util::randomId(6);
    }

    private static function cleanLabel(string $value, int $max = 40): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }

    /**
     * یک الگوی دامنه را تمیز و اعتبارسنجی می‌کند.
     * ورودی‌هایی مثل "https://api.x.com/path" به "api.x.com" تبدیل می‌شوند.
     */
    public static function cleanHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        // اگر کاربر آدرس کامل چسبانده، فقط دامنه را برمی‌داریم
        if (strpos($host, '://') !== false) {
            $parsed = parse_url($host);
            $host   = is_array($parsed) && !empty($parsed['host']) ? $parsed['host'] : '';
        }

        // حذف مسیر، پورت، کاربر و اسلش انتهایی
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];
        $host = preg_replace('/^.*@/', '', $host) ?? $host;
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $host = rtrim($host, '.');

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        // '*' تنها یعنی همه‌چیز باز است — این باید از طریق خاموش کردن
        // محدودیت انجام شود، نه یک الگوی گمراه‌کننده در لیست.
        if ($host === '*') {
            return null;
        }

        // IP خام هم قابل قبول است
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return null;
        }

        return $host;
    }
}
