<?php

namespace Relay;

/**
 * بدنه درخواست ورودی و آماده‌سازی آن برای ارسال به مقصد.
 *
 * سه حالت خروجی:
 *   MODE_NONE       بدنه‌ای وجود ندارد
 *   MODE_STRING     بدنه به‌صورت رشته در حافظه است
 *   MODE_STREAM     بدنه باید استریم شود (فایل حجیم)
 *   MODE_MULTIPART  بدنه از $_POST/$_FILES بازسازی شده است
 *
 * حالت MODE_MULTIPART برای زمانی است که PHP خودش multipart/form-data را
 * پارس کرده و php://input خالی شده — که روی اکثر هاست‌های اشتراکی
 * (PHP-FPM / LSAPI) اتفاق می‌افتد و گرنه آپلود فایل کار نمی‌کند.
 */
class RequestBody
{
    public const MODE_NONE      = 'none';
    public const MODE_STRING    = 'string';
    public const MODE_STREAM    = 'stream';
    public const MODE_MULTIPART = 'multipart';

    /** @var string */
    private $mode = self::MODE_NONE;

    /** @var string */
    private $content = '';

    /** @var resource|null */
    private $stream = null;

    /** @var int|null اندازه بدنه؛ null یعنی نامعلوم (chunked) */
    private $size = null;

    /** @var array<string,mixed> فیلدهای multipart برای curl */
    private $fields = [];

    /** @var bool آیا هدر Content-Type اصلی باید حذف شود */
    private $overridesContentType = false;

    /** @var array<int,string> فایل‌های موقتی که ما ساختیم و باید پاک شوند */
    private $tempFiles = [];

    private function __construct()
    {
    }

    public static function capture(IncomingRequest $request, Config $config, Logger $logger): self
    {
        $body = new self();

        if (!$request->hasBody()) {
            return $body;
        }

        $maxBody     = $config->int('http.max_request_body', 52428800);
        $threshold   = max(0, $config->int('http.stream_upload_threshold', 2097152));
        $contentType = strtolower($request->contentType());
        $length      = $request->contentLength();

        if ($maxBody > 0 && $length !== null && $length > $maxBody) {
            throw new RelayException(
                413,
                'request_too_large',
                'حجم درخواست (' . Util::humanBytes($length) . ') از حد مجاز ('
                . Util::humanBytes($maxBody) . ') بیشتر است.'
            );
        }

        $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

        // PHP بدنه multipart را پارس کرده است؟
        if ($isMultipart && ($_POST !== [] || $_FILES !== [])) {
            $body->mode                 = self::MODE_MULTIPART;
            $body->fields               = self::buildMultipartFields($logger);
            $body->overridesContentType = true;

            return $body;
        }

        $handle = @fopen('php://input', 'rb');

        if ($handle === false) {
            $logger->warning('php://input قابل خواندن نبود؛ بدنه بدون ارسال رد شد.');

            return $body;
        }

        // بدنه بزرگ یا با طول نامعلوم (chunked) → استریم
        if (($length === null && $request->isChunked()) || ($threshold > 0 && $length !== null && $length > $threshold)) {
            $body->mode   = self::MODE_STREAM;
            $body->stream = $handle;
            $body->size   = $length;

            return $body;
        }

        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false || $content === '') {
            return $body;
        }

        if ($maxBody > 0 && strlen($content) > $maxBody) {
            throw new RelayException(
                413,
                'request_too_large',
                'حجم درخواست از حد مجاز بیشتر است.'
            );
        }

        $body->mode    = self::MODE_STRING;
        $body->content = $content;
        $body->size    = strlen($content);

        return $body;
    }

    /**
     * ساخت آرایه فیلدهای multipart برای CURLOPT_POSTFIELDS از $_POST و $_FILES.
     *
     * @return array<string,mixed>
     */
    private static function buildMultipartFields(Logger $logger): array
    {
        $fields = [];

        foreach (self::flatten($_POST) as $name => $value) {
            $fields[$name] = $value;
        }

        foreach (self::normalizeFiles($_FILES) as $name => $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $logger->warning('فایل آپلودی با خطا رد شد.', [
                    'field' => $name,
                    'error' => $file['error'] ?? null,
                ]);
                continue;
            }

            $tmp = (string) ($file['tmp_name'] ?? '');

            if ($tmp === '' || !is_readable($tmp)) {
                continue;
            }

            $fields[$name] = new \CURLFile(
                $tmp,
                (string) ($file['type'] ?? 'application/octet-stream'),
                (string) ($file['name'] ?? 'file')
            );
        }

        return $fields;
    }

    /**
     * تبدیل آرایه تودرتو به کلیدهای a[b][c] که فرم‌های HTTP انتظار دارند.
     *
     * @param array<mixed> $data
     * @return array<string,string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $flat += self::flatten($value, $name);
            } elseif (is_scalar($value) || $value === null) {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * ساختار عجیب $_FILES را (شامل آرایه‌ای از فایل‌ها) به لیست صاف تبدیل می‌کند.
     *
     * @param array<string,mixed> $files
     * @return array<string,array<string,mixed>>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $info) {
            if (!is_array($info) || !isset($info['name'])) {
                continue;
            }

            if (!is_array($info['name'])) {
                $normalized[(string) $field] = $info;
                continue;
            }

            // حالت field[] — چند فایل با یک نام
            foreach (array_keys($info['name']) as $index) {
                $normalized[$field . '[' . $index . ']'] = [
                    'name'     => $info['name'][$index] ?? 'file',
                    'type'     => $info['type'][$index] ?? 'application/octet-stream',
                    'tmp_name' => $info['tmp_name'][$index] ?? '',
                    'error'    => $info['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $info['size'][$index] ?? 0,
                ];
            }
        }

        return $normalized;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isEmpty(): bool
    {
        return $this->mode === self::MODE_NONE;
    }

    public function content(): string
    {
        return $this->content;
    }

    /** @return resource|null */
    public function stream()
    {
        return $this->stream;
    }

    public function size(): ?int
    {
        return $this->size;
    }

    /** @return array<string,mixed> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function overridesContentType(): bool
    {
        return $this->overridesContentType;
    }

    /**
     * خلاصه بدنه برای لاگ.
     */
    public function describe(): string
    {
        switch ($this->mode) {
            case self::MODE_STRING:
                return 'string(' . strlen($this->content) . ')';
            case self::MODE_STREAM:
                return 'stream(' . ($this->size === null ? 'chunked' : (string) $this->size) . ')';
            case self::MODE_MULTIPART:
                return 'multipart(' . count($this->fields) . ' فیلد)';
            default:
                return 'none';
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
            $this->stream = null;
        }

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];
    }
}
