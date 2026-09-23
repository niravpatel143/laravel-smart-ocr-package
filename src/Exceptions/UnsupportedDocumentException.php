<?php declare(strict_types=1);

namespace LaravelSmartOCR\Exceptions;

class UnsupportedDocumentException extends OCRException
{
    public static function forFormat(string $provider, string $format): self
    {
        return new self("Provider [{$provider}] does not support document format [{$format}].");
    }
}
