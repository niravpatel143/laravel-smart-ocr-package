<?php declare(strict_types=1);

namespace LaravelSmartOCR\Drivers;

use LaravelSmartOCR\Results\OcrResult;

interface CloudOcrCapable
{
    public function read(mixed $document, array $options = []): OcrResult;
}
