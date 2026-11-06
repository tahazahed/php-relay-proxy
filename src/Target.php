<?php

namespace Relay;

/**
 * مقصد اعتبارسنجی‌شده یک درخواست.
 */
class Target
{
    /** @var string آدرس کامل */
    public $url;

    /** @var string */
    public $scheme;

    /** @var string */
    public $host;

    /** @var int */
    public $port;

    /** @var array<int,string> IP هایی که دامنه به آن‌ها resolve شد */
    public $ips = [];

    public function __construct(string $url, string $scheme, string $host, int $port, array $ips = [])
    {
        $this->url    = $url;
        $this->scheme = $scheme;
        $this->host   = $host;
        $this->port   = $port;
        $this->ips    = $ips;
    }

    /**
     * آدرس با اسکیم و هاست (برای هدر Host و Origin).
     */
    public function origin(): string
    {
        $defaultPort = $this->scheme === 'https' ? 443 : 80;

        return $this->scheme . '://' . $this->host . ($this->port === $defaultPort ? '' : ':' . $this->port);
    }

    /**
     * مقدار هدر Host که به مقصد فرستاده می‌شود.
     */
    public function hostHeader(): string
    {
        $defaultPort = $this->scheme === 'https' ? 443 : 80;

        return $this->host . ($this->port === $defaultPort ? '' : ':' . $this->port);
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
