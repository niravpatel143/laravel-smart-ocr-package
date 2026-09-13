<?php

namespace LaravelSmartOCR\Services;

use Illuminate\Support\Manager;
use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Drivers\TesseractDriver;
use LaravelSmartOCR\Exceptions\DriverNotAvailableException;

class OCRManager extends Manager
{
    /**
     * Create the Tesseract driver — the only built-in driver.
     */
    protected function createTesseractDriver(): OCRDriver
    {
        return new TesseractDriver(
            $this->config->get('smart-ocr.drivers.tesseract', [])
        );
    }

    public function getDefaultDriver(): string
    {
        return $this->config->get('smart-ocr.default', 'tesseract');
    }

    /**
     * Resolve an OCR driver by name.
     *
     * Custom drivers can be registered via SmartOCR::extend() — the parent
     * Manager class provides that method and stores factories in $customCreators.
     * Unknown drivers throw DriverNotAvailableException instead of silently failing.
     */
    public function driver($driver = null)
    {
        $driver = $driver ?? $this->getDefaultDriver();

        // Let the parent handle custom drivers registered via extend()
        if (isset($this->customCreators[$driver])) {
            $resolved = parent::driver($driver);

            if (! $resolved instanceof OCRDriver) {
                throw new DriverNotAvailableException(
                    "Custom driver factory for [{$driver}] must return an instance of OCRDriver."
                );
            }

            return $resolved;
        }

        // Only tesseract is a built-in driver; any other name is unknown.
        if ($driver !== 'tesseract') {
            throw DriverNotAvailableException::unknown($driver);
        }

        return $this->createTesseractDriver();
    }

    // ── Convenience pass-throughs ──────────────────────────────────────────

    public function extract($document, array $options = []): array
    {
        return $this->driver()->extract($document, $options);
    }

    public function extractWithTemplate($document, $templateId, array $options = []): array
    {
        $rawText = $this->driver()->extract($document, $options);
        return app('smart-ocr.templates')->applyTemplate($rawText, $templateId);
    }

    public function extractTable($document, array $options = []): array
    {
        return $this->driver()->extractTable($document, $options);
    }

    public function extractBarcode($document, array $options = []): array
    {
        return $this->driver()->extractBarcode($document, $options);
    }

    public function extractQRCode($document, array $options = []): array
    {
        return $this->driver()->extractQRCode($document, $options);
    }
}
