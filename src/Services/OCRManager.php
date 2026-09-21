<?php

namespace LaravelSmartOCR\Services;

use Illuminate\Http\UploadedFile;
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
        return $this->config->get('smart-ocr.default', 'tesseract');
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

    /**
     * Extract all text from an image or document. Returns just the text string.
     * Accepts a file path, an UploadedFile, or a temp path.
     * Uses the configured default driver (tesseract by default — 100% free).
     *
     * Usage:
     *   $text = SmartOCR::getText($request->file('image'));
     *   $text = SmartOCR::getText('/path/to/image.jpg');
     */
    public function getText($document, string $language = 'eng'): string
    {
        $path = $document instanceof UploadedFile
            ? $document->getPathname()
            : $document;

        $result = $this->driver()->extract($path, ['language' => $language]);

        return $result['text'] ?? '';
    }

    /**
     * Extract all text using Tesseract — always free, always offline, no API key needed.
     * Install Tesseract once: https://github.com/UB-Mannheim/tesseract/wiki (Windows)
     *                          apt install tesseract-ocr (Linux)
     *                          brew install tesseract (macOS)
     *
     * Usage:
     *   $text = SmartOCR::freeText($request->file('image'));
     *   $text = SmartOCR::freeText('/path/to/scan.png', 'fra'); // French
     */
    public function freeText($document, string $language = 'eng'): string
    {
        $path = $document instanceof UploadedFile
            ? $document->getPathname()
            : $document;

        $result = $this->driver('tesseract')->extract($path, ['language' => $language]);

        return $result['text'] ?? '';
    }

    public function extract($document, array $options = []): array
    {
        return $this->driver()->extract($document, $options);
    }

    public function extractText($document, array $options = []): array
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
