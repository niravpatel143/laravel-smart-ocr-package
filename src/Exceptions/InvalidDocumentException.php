<?php

namespace LaravelSmartOCR\Exceptions;

use RuntimeException;

class InvalidDocumentException extends RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self("Document file not found or not readable.");
    }

    public static function oversized(int $size, int $max): self
    {
        $sizeMb = round($size / 1048576, 1);
        $maxMb  = round($max / 1048576, 1);
        return new self("Document size ({$sizeMb} MB) exceeds the maximum allowed ({$maxMb} MB).");
    }

    public static function forbiddenExtension(string $ext, array $allowed): self
    {
        return new self(
            "Extension [{$ext}] is not allowed. Allowed: " . implode(', ', $allowed) . "."
        );
    }

    public static function forbiddenMimeType(string $mime, array $allowed): self
    {
        return new self(
            "MIME type [{$mime}] is not allowed. Allowed: " . implode(', ', $allowed) . "."
        );
    }

    public static function emptyFile(): self
    {
        return new self("Document file is empty.");
    }
}
