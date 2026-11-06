<?php

namespace Relay\Transport;

interface Transport
{
    /**
     * درخواست را به مقصد می‌فرستد.
     *
     * @param callable(int,array<int,array{0:string,1:string}>):void $onHeaders
     *        وقتی هدرهای پاسخ کامل شد صدا زده می‌شود (قبل از اولین chunk).
     * @param callable(string):void $onChunk
     *        برای هر تکه از بدنه پاسخ صدا زده می‌شود.
     */
    public function send(OutgoingRequest $request, callable $onHeaders, callable $onChunk): TransportResult;

    public function name(): string;

    public static function isAvailable(): bool;
}
