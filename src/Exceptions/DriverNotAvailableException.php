<?php

namespace LaravelSmartOCR\Exceptions;

use RuntimeException;

class DriverNotAvailableException extends RuntimeException
{
    public static function unknown(string $driver, array $available = ['claude', 'openai', 'pdf', 'tesseract']): self
    {
        $list = implode(', ', $available);
        return new self(
            "OCR driver [{$driver}] is not registered. Built-in drivers: {$list}. " .
            "Register custom drivers via SmartOCR::extend()."
        );
    }

    public static function missingClass(string $driver, string $class): self
    {
        return new self(
            "OCR driver [{$driver}] references class [{$class}] which does not exist."
        );
    }
}
