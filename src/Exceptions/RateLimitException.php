<?php declare(strict_types=1);

namespace LaravelSmartOCR\Exceptions;

class RateLimitException extends OCRException
{
    public function __construct(string $provider, private int $retryAfter = 60)
    {
        parent::__construct("Rate limit exceeded for provider [{$provider}]. Retry after {$retryAfter} seconds.");
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
