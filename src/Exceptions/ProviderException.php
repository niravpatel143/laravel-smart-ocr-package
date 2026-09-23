<?php declare(strict_types=1);

namespace LaravelSmartOCR\Exceptions;

class ProviderException extends OCRException
{
    public static function fromProviderError(string $provider, string $message, int $code = 0): self
    {
        return new self("[{$provider}] {$message}", $code);
    }
}
