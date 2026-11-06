<?php

namespace Relay\Transport;

use Relay\RequestBody;
use Relay\Target;

/**
 * درخواستی که رله به مقصد می‌فرستد.
 */
class OutgoingRequest
{
    /** @var string */
    public $method;

    /** @var Target */
    public $target;

    /** @var array<int,string> هدرها به شکل "Name: value" */
    public $headers = [];

    /** @var RequestBody */
    public $body;

    /** @var bool آیا ریدایرکت باید به فراخوان برگردانده شود */
    public $followRedirect = true;

    public function __construct(string $method, Target $target, array $headers, RequestBody $body)
    {
        $this->method  = $method;
        $this->target  = $target;
        $this->headers = $headers;
        $this->body    = $body;
    }

    public function url(): string
    {
        return $this->target->url;
    }

    public function expectsResponseBody(): bool
    {
        return $this->method !== 'HEAD';
    }
}
