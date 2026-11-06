<?php

namespace Relay;

/**
 * خطایی که باید به‌صورت پاسخ HTTP تمیز به کلاینت برگردد.
 */
class RelayException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $errorCode;

    /** @var array<string,mixed> */
    private $context;

    /**
     * @param array<string,mixed> $context اطلاعات اضافه برای لاگ (به کلاینت نمی‌رود)
     */
    public function __construct(int $status, string $errorCode, string $message, array $context = [])
    {
        parent::__construct($message);
        $this->status    = $status;
        $this->errorCode = $errorCode;
        $this->context   = $context;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function badRequest(string $code, string $message, array $ctx = []): self
    {
        return new self(400, $code, $message, $ctx);
    }

    public static function unauthorized(string $code, string $message, array $ctx = []): self
    {
        return new self(401, $code, $message, $ctx);
    }

    public static function forbidden(string $code, string $message, array $ctx = []): self
    {
        return new self(403, $code, $message, $ctx);
    }
}
