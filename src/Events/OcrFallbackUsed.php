<?php declare(strict_types=1);
namespace LaravelSmartOCR\Events;

use LaravelSmartOCR\Results\OcrResult;

class OcrFallbackUsed
{
    public function __construct(
        public readonly OcrResult $result,
        public readonly string $requestedDriver,
        public readonly string $actualDriver,
        public readonly string $reason,
    ) {}
}
