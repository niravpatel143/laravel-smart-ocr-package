<?php declare(strict_types=1);

namespace LaravelSmartOCR\Exceptions;

class ConfigurationException extends OCRException
{
    public static function missingKey(string $driver, string $key): self
    {
        return new self("Driver [{$driver}] is missing required configuration key [{$key}].");
    }

    public static function missingDependency(string $driver, string $package): self
    {
        return new self("Driver [{$driver}] requires package [{$package}]. Install it with: composer require {$package}");
    }
}
