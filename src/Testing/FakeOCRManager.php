<?php declare(strict_types=1);

namespace LaravelSmartOCR\Testing;

use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Services\OCRManager;

class FakeOCRManager extends OCRManager
{
    public function __construct(
        private readonly SmartOCRFake $fake,
        \Illuminate\Contracts\Foundation\Application $app
    ) {
        parent::__construct($app);
    }

    public function read(mixed $source, array $options = []): OcrResult
    {
        $path   = is_string($source) ? $source : (method_exists($source, 'path') ? $source->path() : (string) $source);
        $driver = $options['driver'] ?? $this->getDefaultDriver();
        return $this->fake->recordRead($path, $driver, $options);
    }
}
