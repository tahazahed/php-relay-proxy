<?php

namespace Relay;

/**
 * لاگ‌نویس JSON Lines با یک فایل برای هر روز.
 */
class Logger
{
    private const LEVELS = [
        'debug'   => 10,
        'info'    => 20,
        'warning' => 30,
        'error'   => 40,
        'off'     => 100,
    ];

    /** @var Config */
    private $config;

    /** @var bool */
    private $enabled;

    /** @var int */
    private $threshold;

    /** @var string */
    private $dir;

    public function __construct(Config $config)
    {
        $this->config    = $config;
        $this->enabled   = $config->bool('log.enabled', true);
        $this->dir       = $config->str('log.dir', dirname(__DIR__) . '/storage/logs');
        $level           = strtolower($config->str('log.level', 'info'));
        $this->threshold = self::LEVELS[$level] ?? self::LEVELS['info'];

        if ($level === 'off') {
            $this->enabled = false;
        }
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $severity = self::LEVELS[$level] ?? self::LEVELS['info'];

        if ($severity < $this->threshold) {
            return;
        }

        if (!$this->ensureDir()) {
            return;
        }

        $record = array_merge(
            [
                'time'    => gmdate('c'),
                'level'   => $level,
                'message' => $this->redact($message),
            ],
            $this->redactArray($context)
        );

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        $file = $this->currentFile();

        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        // اندازه همین رکورد، تخمین میانگین است — رکوردها هم‌شکل‌اند
        $this->enforceRecordCap($file, strlen($line) + strlen(PHP_EOL));

        if (mt_rand(1, 200) === 1) {
            $this->rotate();
        }
    }

    /**
     * سقف تعداد رکورد در فایل لاگ.
     *
     * هزینه روی مسیر داغ باید نزدیک صفر باشد، پس:
     *   ۱. تخمین میانگین اندازه رکورد از خود رکوردی که همین الان نوشتیم
     *      می‌آید — هیچ خواندنی از فایل لازم نیست.
     *   ۲. فقط یک stat ارزان برای گرفتن اندازه فایل انجام می‌شود.
     *   ۳. وقتی از سقف رد شد، فایل تا TRIM_RATIO برابر سقف کوتاه می‌شود
     *      نه دقیقاً تا سقف. این «فاصله» باعث می‌شود برش به‌جای هر نوشتن،
     *      یک بار در هر ۲۰٪ ظرفیت اتفاق بیفتد.
     *
     * نتیجه: فایل هیچ‌وقت از سقف رد نمی‌شود و هزینه سرشکن‌شده ناچیز است.
     */
    private const TRIM_RATIO = 0.8;

    private function enforceRecordCap(string $file, int $recordSize): void
    {
        $maxRecords = $this->config->int('log.max_records', 2000);

        if ($maxRecords <= 0) {
            return;
        }

        $recordSize = max(1, $recordSize);

        clearstatcache(true, $file);

        $size = @filesize($file);

        if ($size === false || (int) ($size / $recordSize) <= $maxRecords) {
            return;
        }

        $keep = max(10, (int) ($maxRecords * self::TRIM_RATIO));

        $this->trimToLastRecords($file, $keep, $recordSize);
    }

    /**
     * فایل را دقیقاً به N رکورد آخر کوتاه می‌کند (زیر قفل انحصاری).
     */
    private function trimToLastRecords(string $file, int $keep, int $recordSize): bool
    {
        $handle = @fopen($file, 'r+');

        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            // ممکن است پروسه دیگری در همین فاصله برش زده باشد
            clearstatcache(true, $file);
            $size = @filesize($file);

            if ($size === false || $size <= 0) {
                return false;
            }

            // فقط دُمِ فایل خوانده می‌شود تا حافظه پر نشود؛ ۱.۵ برابر
            // فضای لازم تا مطمئن شویم N رکورد کامل داخلش هست.
            $readCap = (int) min(max($keep * $recordSize * 1.5, 131072), 16777216);
            $offset  = max(0, $size - $readCap);

            fseek($handle, $offset);
            $buffer = stream_get_contents($handle);

            if ($buffer === false) {
                return false;
            }

            $lines = explode("\n", $buffer);

            // اگر از وسط فایل خوانده‌ایم، خط اول احتمالاً ناقص است
            if ($offset > 0) {
                array_shift($lines);
            }

            $lines = array_values(array_filter(
                $lines,
                static function (string $line): bool {
                    return trim($line) !== '';
                }
            ));

            if (count($lines) <= $keep) {
                return false;
            }

            $body = implode(PHP_EOL, array_slice($lines, -$keep)) . PHP_EOL;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $body);
            fflush($handle);

            return true;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * تعداد رکوردهای فایل لاگ (برای نمایش در پنل).
     */
    public function countRecords(?string $file = null): int
    {
        $file = $file ?? $this->currentFile();

        if (!is_file($file)) {
            return 0;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return 0;
        }

        $count = 0;

        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        fclose($handle);

        return $count;
    }

    public function maxRecords(): int
    {
        return $this->config->int('log.max_records', 2000);
    }

    public function currentFile(): string
    {
        return $this->dir . '/relay-' . gmdate('Y-m-d') . '.log';
    }

    /**
     * فایل‌های لاگ موجود، جدیدترین اول.
     *
     * @return array<int,string>
     */
    public function files(): array
    {
        $files = @glob($this->dir . '/relay-*.log');

        if (!is_array($files)) {
            return [];
        }

        rsort($files);

        return $files;
    }

    /**
     * آخرین رکوردهای لاگ (برای پنل).
     *
     * @return array<int,array<string,mixed>>
     */
    public function tail(int $lines = 100, ?string $file = null): array
    {
        $file = $file ?? $this->currentFile();

        if (!is_file($file)) {
            return [];
        }

        $records = [];
        $handle  = @fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        // خواندن از انتها با بافر
        $buffer   = '';
        $chunk    = 8192;
        $position = filesize($file) ?: 0;

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read     = (int) min($chunk, $position);
            $position -= $read;

            fseek($handle, $position);
            $buffer = fread($handle, $read) . $buffer;
        }

        fclose($handle);

        $rawLines = array_filter(array_map('trim', explode("\n", $buffer)));
        $rawLines = array_slice($rawLines, -$lines);

        foreach ($rawLines as $rawLine) {
            $decoded = json_decode($rawLine, true);
            $records[] = is_array($decoded) ? $decoded : ['level' => 'info', 'message' => $rawLine];
        }

        return array_reverse($records);
    }

    /**
     * بریدن بدنه برای لاگ (طبق تنظیمات).
     */
    public function bodySnippet(string $body): ?string
    {
        if (!$this->config->bool('log.log_bodies', false)) {
            return null;
        }

        $limit = max(0, $this->config->int('log.body_limit', 2048));

        if ($limit === 0) {
            return null;
        }

        $snippet = substr($body, 0, $limit);

        if (strlen($body) > $limit) {
            $snippet .= '…[' . (strlen($body) - $limit) . ' بایت بیشتر]';
        }

        return $this->redact($snippet);
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }

        return @mkdir($this->dir, 0775, true);
    }

    private function rotate(): void
    {
        $files = $this->files(); // جدیدترین اول
        $days  = $this->config->int('log.retention_days', 7);

        if ($days > 0) {
            $threshold = time() - ($days * 86400);

            foreach ($files as $file) {
                if (@filemtime($file) < $threshold) {
                    @unlink($file);
                }
            }
        }

        // سقف تعداد فایل، مستقل از سن آن‌ها
        $maxFiles = $this->config->int('log.max_files', 7);

        if ($maxFiles > 0 && count($files) > $maxFiles) {
            foreach (array_slice($files, $maxFiles) as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * ماسک کردن اطلاعات حساس بر اساس الگوهای تنظیمات.
     */
    public function redact(string $value): string
    {
        foreach ($this->config->arr('log.redact_patterns') as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            $replaced = @preg_replace($pattern, '[REDACTED]', $value);

            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            }
        }

        return $data;
    }
}
