<?php declare(strict_types=1);

namespace LaravelSmartOCR\Events;

class OcrFailed
{
    public function __construct(
        public readonly \Throwable $exception,
        public readonly string $driver,
        public readonly mixed $document,
    ) {}
}
