<?php

namespace LaravelSmartOCR\Exceptions;

use RuntimeException;

class DriverNotAvailableException extends RuntimeException
{
    public static function unknown(string $driver): self
    {
        return new self(
            "OCR driver [{$driver}] is not registered. Available drivers: tesseract. " .
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
