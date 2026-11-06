<?php

namespace Relay;

/**
 * بازنویسی آدرس‌های مقصد داخل بدنه پاسخ، تا کلاینت دوباره از رله عبور کند.
 *
 * مثال: تلگرام در پاسخ getFile فقط file_path می‌دهد، ولی بعضی سرویس‌ها
 * آدرس کامل برمی‌گردانند؛ آن آدرس‌ها روی سرور داخلی قابل دسترسی نیستند.
 */
class BodyRewriter
{
    /** @var Config */
    private $config;

    /** @var string آدرس پایه رله برای همین درخواست */
    private $relayBase;

    public function __construct(Config $config, string $relayBase)
    {
        $this->config    = $config;
        $this->relayBase = rtrim($relayBase, '/');
    }

    public function enabled(): bool
    {
        return $this->config->bool('rewrite.enabled', false)
            && $this->config->arr('rewrite.rules') !== [];
    }

    /**
     * آیا این پاسخ باید بافر و بازنویسی شود؟
     */
    public function appliesTo(string $contentType, ?int $contentLength): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $maxSize = $this->config->int('rewrite.max_size', 1048576);

        if ($maxSize > 0 && $contentLength !== null && $contentLength > $maxSize) {
            return false;
        }

        $contentType = strtolower($contentType);

        foreach ($this->config->arr('rewrite.content_types') as $needle) {
            if (is_string($needle) && $needle !== '' && strpos($contentType, strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * اعمال قواعد بازنویسی روی بدنه.
     */
    public function apply(string $body): string
    {
        foreach ($this->config->arr('rewrite.rules') as $rule) {
            if (!is_array($rule) || !isset($rule['from'], $rule['to'])) {
                continue;
            }

            $from = (string) $rule['from'];
            $to   = str_replace('{relay}', $this->relayBase, (string) $rule['to']);

            if ($from === '') {
                continue;
            }

            // نسخه JSON-escape شده هم جایگزین می‌شود (اسلش‌های \/ )
            $body = str_replace(
                [$from, str_replace('/', '\\/', $from)],
                [$to, str_replace('/', '\\/', $to)],
                $body
            );
        }

        return $body;
    }
}
