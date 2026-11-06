<?php

namespace Relay\Transport;

/**
 * نتیجه یک درخواست به مقصد.
 */
class TransportResult
{
    /** @var int کد وضعیت HTTP پاسخ مقصد */
    public $status = 0;

    /** @var array<int,array{0:string,1:string}> هدرهای پاسخ به ترتیب اصلی */
    public $headers = [];

    /** @var string|null اگر ریدایرکت بود و دنبال نشد، آدرس مقصد بعدی */
    public $redirectLocation = null;

    /** @var bool آیا هدرها به کلاینت تحویل داده شدند */
    public $headersDelivered = false;

    /** @var int تعداد بایت بدنه دریافتی */
    public $bytes = 0;

    /** @var string|null پیام خطای شبکه */
    public $error = null;

    /** @var int کد خطای curl */
    public $errno = 0;

    /** @var float مدت زمان بر حسب ثانیه */
    public $duration = 0.0;

    /** @var string|null دلیل قطع عمدی: redirect | too_large */
    public $abortReason = null;

    /** @var string نام ترنسپورت استفاده‌شده */
    public $transport = '';

    public function isRedirect(): bool
    {
        return $this->redirectLocation !== null;
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * مقدار یک هدر پاسخ (اولین تطبیق).
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $header) {
            if (strcasecmp($header[0], $name) === 0) {
                return $header[1];
            }
        }

        return null;
    }
}
