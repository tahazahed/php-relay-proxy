<?php

namespace Relay;

/**
 * محدودکننده نرخ با پنجره لغزان، مبتنی بر فایل.
 * روی هاست اشتراکی (بدون Redis/APCu) کار می‌کند.
 */
class RateLimiter
{
    /** @var Config */
    private $config;

    /** @var int */
    private $remaining = 0;

    /** @var int */
    private $resetAt = 0;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }

    public function resetAt(): int
    {
        return $this->resetAt;
    }

    public function limit(): int
    {
        return max(1, $this->config->int('rate_limit.max_requests', 300));
    }

    /**
     * یک درخواست ثبت می‌کند. در صورت عبور از حد، استثنای 429 پرتاب می‌شود.
     */
    public function hit(string $keyLabel, string $ip): void
    {
        if (!$this->config->bool('rate_limit.enabled', true)) {
            $this->remaining = $this->limit();
            $this->resetAt   = time();

            return;
        }

        $window = max(1, $this->config->int('rate_limit.window', 60));
        $limit  = $this->limit();
        $dir    = $this->config->str('rate_limit.dir', dirname(__DIR__) . '/storage/rate');

        if (!$this->ensureDir($dir)) {
            // اگر نشد بنویسیم، رله را قطع نمی‌کنیم — فقط محدودیت اعمال نمی‌شود.
            $this->remaining = $limit;
            $this->resetAt   = time() + $window;

            return;
        }

        $identity = $this->identity($keyLabel, $ip);
        $file     = $dir . '/' . hash('sha256', $identity) . '.json';

        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            $this->remaining = $limit;
            $this->resetAt   = time() + $window;

            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                $this->remaining = $limit;
                $this->resetAt   = time() + $window;

                return;
            }

            $contents = stream_get_contents($handle);
            $stamps   = [];

            if (is_string($contents) && $contents !== '') {
                $decoded = json_decode($contents, true);

                if (is_array($decoded)) {
                    $stamps = array_map('floatval', array_filter($decoded, 'is_numeric'));
                }
            }

            $now    = microtime(true);
            $cutoff = $now - $window;

            // فقط زمان‌های داخل پنجره را نگه دار
            $stamps = array_values(array_filter(
                $stamps,
                static function ($stamp) use ($cutoff) {
                    return $stamp > $cutoff;
                }
            ));

            $oldest        = $stamps === [] ? $now : min($stamps);
            $this->resetAt = (int) ceil($oldest + $window);

            if (count($stamps) >= $limit) {
                $this->remaining = 0;
                $retryAfter      = max(1, $this->resetAt - (int) $now);

                throw new RelayException(
                    429,
                    'rate_limit_exceeded',
                    'تعداد درخواست بیش از حد مجاز است. ' . $retryAfter . ' ثانیه بعد تلاش کنید.',
                    ['retry_after' => $retryAfter, 'limit' => $limit, 'window' => $window]
                );
            }

            $stamps[]        = $now;
            $this->remaining = max(0, $limit - count($stamps));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($stamps));
            fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }

        // پاک‌سازی تصادفی فایل‌های قدیمی (۱ درصد درخواست‌ها)
        if (mt_rand(1, 100) === 1) {
            $this->cleanup($dir, $window);
        }
    }

    private function identity(string $keyLabel, string $ip): string
    {
        switch ($this->config->str('rate_limit.by', 'key+ip')) {
            case 'ip':
                return 'ip:' . $ip;
            case 'key':
                return 'key:' . $keyLabel;
            default:
                return 'key:' . $keyLabel . '|ip:' . $ip;
        }
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        return @mkdir($dir, 0775, true) && is_writable($dir);
    }

    private function cleanup(string $dir, int $window): void
    {
        $files = @glob($dir . '/*.json');

        if (!is_array($files)) {
            return;
        }

        $threshold = time() - max(3600, $window * 10);

        foreach ($files as $file) {
            if (@filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }
}
