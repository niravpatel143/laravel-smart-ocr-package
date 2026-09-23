<?php declare(strict_types=1);

namespace LaravelSmartOCR\Exceptions;

class AuthenticationException extends OCRException
{
    public static function invalidCredentials(string $provider): self
    {
        return new self("Authentication failed for provider [{$provider}]. Check your credentials.");
    }
}
