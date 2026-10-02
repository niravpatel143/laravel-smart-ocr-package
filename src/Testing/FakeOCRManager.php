<?php declare(strict_types=1);

namespace LaravelSmartOCR\Testing;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Services\OCRManager;
use LaravelSmartOCR\Services\OcrDriverBuilder;

class FakeOCRManager extends OCRManager
{
    public function __construct(
        private readonly SmartOCRFake $fake,
        \Illuminate\Contracts\Foundation\Application $app
    ) {
        parent::__construct($app);
    }

    /**
     * Override driver() to return a builder backed by the fake,
     * so SmartOCR::driver('google')->read() is intercepted correctly.
     */
    public function driver($driverName = null): OcrDriverBuilder
    {
        $name       = $driverName ?? $this->getDefaultDriver();
        $fakeDriver = new class($this->fake, $name) implements OCRDriver {
            public function __construct(
                private readonly SmartOCRFake $fake,
                private readonly string $driverName,
            ) {}

            public function extract($document, array $options = []): array
            {
                return $this->fake->recordRead((string) $document, $this->driverName, $options)->toArray();
            }

            public function extractText($document, array $options = []): array      { return $this->extract($document, $options); }
            public function extractTable($document, array $options = []): array     { return []; }
            public function extractBarcode($document, array $options = []): array   { return []; }
            public function extractQRCode($document, array $options = []): array    { return []; }
            public function getSupportedLanguages(): array                          { return []; }
            public function getSupportedFormats(): array                            { return []; }
        };

        return new FakeOcrDriverBuilder($fakeDriver, $name, $this, $this->fake);
    }

    public function read(mixed $source, array $options = []): OcrResult
    {
        $path   = is_string($source) ? $source : (method_exists($source, 'path') ? $source->path() : (string) $source);
        $driver = $options['driver'] ?? $this->getDefaultDriver();
        return $this->fake->recordRead($path, $driver, $options);
    }
}
