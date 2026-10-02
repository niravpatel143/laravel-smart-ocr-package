<?php declare(strict_types=1);

namespace LaravelSmartOCR\Testing;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Services\OCRManager;
use LaravelSmartOCR\Services\OcrDriverBuilder;

/**
 * Driver builder used inside FakeOCRManager.
 * Overrides read() so SmartOCR::driver('x')->read($path) is intercepted
 * and recorded by SmartOCRFake instead of hitting a real API.
 */
class FakeOcrDriverBuilder extends OcrDriverBuilder
{
    public function __construct(
        OCRDriver $driver,
        string $driverName,
        OCRManager $manager,
        private readonly SmartOCRFake $fake,
    ) {
        parent::__construct($driver, $driverName, $manager);
        // Store driver name so recordRead gets it
        $this->fakeName = $driverName;
    }

    private string $fakeName;

    public function read(mixed $document = null, array $options = []): OcrResult
    {
        $path = is_string($document) ? $document
            : (($document !== null && method_exists($document, 'path')) ? $document->path() : (string) $document);

        return $this->fake->recordRead($path, $this->fakeName, $options);
    }
}
