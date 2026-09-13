<?php

namespace LaravelSmartOCR\Services;

use Illuminate\Support\Manager;
use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Drivers\ClaudeVisionDriver;
use LaravelSmartOCR\Drivers\OpenAIVisionDriver;
use LaravelSmartOCR\Drivers\PdfTextDriver;
use LaravelSmartOCR\Drivers\TesseractDriver;
use LaravelSmartOCR\Exceptions\DriverNotAvailableException;

class OCRManager extends Manager
{
    protected const BUILT_IN_DRIVERS = ['claude', 'openai', 'pdf', 'tesseract'];

    public function getDefaultDriver(): string
    {
        return $this->config->get('smart-ocr.default', 'claude');
    }

    /**
     * Resolve an OCR driver by name.
     * Custom drivers: SmartOCR::extend('name', fn() => new MyDriver())
     * Built-in drivers: claude, openai, pdf, tesseract
     */
    public function driver($driver = null)
    {
        $driver = $driver ?? $this->getDefaultDriver();

        if (isset($this->customCreators[$driver])) {
            $resolved = parent::driver($driver);

            if (! $resolved instanceof OCRDriver) {
                throw new DriverNotAvailableException(
                    "Custom driver factory for [{$driver}] must return an instance of OCRDriver."
                );
            }

            return $resolved;
        }

        return match ($driver) {
            'claude'    => $this->createClaudeDriver(),
            'openai'    => $this->createOpenAIDriver(),
            'pdf'       => $this->createPdfDriver(),
            'tesseract' => $this->createTesseractDriver(),
            default     => throw DriverNotAvailableException::unknown($driver, self::BUILT_IN_DRIVERS),
        };
    }

    // ── Driver factories ──────────────────────────────────────────────────

    protected function createClaudeDriver(): OCRDriver
    {
        return new ClaudeVisionDriver(
            $this->config->get('smart-ocr.drivers.claude', [])
        );
    }

    protected function createOpenAIDriver(): OCRDriver
    {
        return new OpenAIVisionDriver(
            $this->config->get('smart-ocr.drivers.openai', [])
        );
    }

    protected function createPdfDriver(): OCRDriver
    {
        return new PdfTextDriver(
            $this->config->get('smart-ocr.drivers.pdf', [])
        );
    }

    protected function createTesseractDriver(): OCRDriver
    {
        // TesseractDriver uses exec() directly — no PHP wrapper package needed.
        // Binary availability is checked lazily inside the driver on first use.
        return new TesseractDriver(
            $this->config->get('smart-ocr.drivers.tesseract', [])
        );
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
