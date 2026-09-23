<?php declare(strict_types=1);

namespace LaravelSmartOCR\Events;

use LaravelSmartOCR\Results\OcrResult;

class OcrCompleted
{
    public function __construct(
        public readonly OcrResult $result,
        public readonly string $driver,
    ) {}
}
