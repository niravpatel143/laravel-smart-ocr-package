<?php declare(strict_types=1);

namespace LaravelSmartOCR\Exceptions;

class OcrTimeoutException extends OCRException
{
    public static function forProvider(string $provider, int $timeout): self
    {
        return new self("OCR request to [{$provider}] timed out after {$timeout} seconds.");
    }
}
