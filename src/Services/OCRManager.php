<?php

namespace LaravelSmartOCR\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Manager;
use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Data\DocumentSource;
use LaravelSmartOCR\Drivers\AwsTextractDriver;
use LaravelSmartOCR\Drivers\AzureVisionDriver;
use LaravelSmartOCR\Drivers\ClaudeVisionDriver;
use LaravelSmartOCR\Drivers\GoogleVisionDriver;
use LaravelSmartOCR\Drivers\OpenAIVisionDriver;
use LaravelSmartOCR\Drivers\PdfTextDriver;
use LaravelSmartOCR\Drivers\TesseractDriver;
use LaravelSmartOCR\Exceptions\DriverNotAvailableException;
use LaravelSmartOCR\Results\OcrResult;

class OCRManager extends Manager
{
    protected const BUILT_IN_DRIVERS = ['claude', 'openai', 'pdf', 'tesseract', 'google', 'aws', 'azure'];

    public function getDefaultDriver(): string
    {
        return $this->config->get('smart-ocr.default', 'tesseract');
    }

    /**
     * Resolve an OCR driver by name and wrap it in OcrDriverBuilder.
     * Custom drivers: SmartOCR::extend('name', fn() => new MyDriver())
     * Built-in drivers: claude, openai, pdf, tesseract, google, aws, azure
     */
    public function driver($driver = null): OcrDriverBuilder
    {
        $driverName = $driver ?? $this->getDefaultDriver();

        if (isset($this->customCreators[$driverName])) {
            $resolved = parent::driver($driverName);

            if (! $resolved instanceof OCRDriver) {
                throw new DriverNotAvailableException(
                    "Custom driver factory for [{$driverName}] must return an instance of OCRDriver."
                );
            }

            return new OcrDriverBuilder($resolved, $driverName, $this);
        }

        $resolvedDriver = match ($driverName) {
            'claude'    => $this->createClaudeDriver(),
            'openai'    => $this->createOpenAIDriver(),
            'pdf'       => $this->createPdfDriver(),
            'tesseract' => $this->createTesseractDriver(),
            'google'    => $this->createGoogleDriver(),
            'aws'       => $this->createAwsDriver(),
            'azure'     => $this->createAzureDriver(),
            default     => throw DriverNotAvailableException::unknown($driverName, self::BUILT_IN_DRIVERS),
        };

        return new OcrDriverBuilder($resolvedDriver, $driverName, $this);
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

    protected function createGoogleDriver(): OCRDriver
    {
        return new GoogleVisionDriver(
            $this->config->get('smart-ocr.drivers.google', [])
        );
    }

    protected function createAwsDriver(): OCRDriver
    {
        return new AwsTextractDriver(
            $this->config->get('smart-ocr.drivers.aws', [])
        );
    }

    protected function createAzureDriver(): OCRDriver
    {
        return new AzureVisionDriver(
            $this->config->get('smart-ocr.drivers.azure', [])
        );
    }

    // ── Fluent from() API ─────────────────────────────────────────────────

    /**
     * Start a fluent OCR pipeline from a document source.
     *
     * Accepts:
     *   - A local file path string
     *   - An Illuminate UploadedFile
     *   - A disk-prefixed path string like "s3:invoices/doc.pdf"
     *   - A DocumentSource instance
     *
     * Returns an OcrDriverBuilder with the source pre-loaded.
     * Chain ->pages('1-3'), ->language('eng'), ->driver('google'), ->read() etc.
     *
     * Usage:
     *   SmartOCR::from('/path/to/doc.pdf')->pages('1-3')->read();
     *   SmartOCR::from($uploadedFile)->driver('google')->read();
     *   SmartOCR::from('s3:bucket/path.pdf')->read();
     */
    public function from(mixed $source): OcrDriverBuilder
    {
        $docSource = DocumentSource::parse($source);
        return $this->driver($this->getDefaultDriver())->withSource($docSource);
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

    public function read($document, array $options = []): OcrResult
    {
        return $this->driver()->read($document, $options);
    }

    public function queue($document, ?string $driver = null, array $options = []): \Illuminate\Foundation\Bus\PendingDispatch
    {
        $driverName = $driver ?? $this->getDefaultDriver();
        $path       = is_string($document) ? $document : ($document instanceof \SplFileInfo ? $document->getPathname() : null);
        return \LaravelSmartOCR\Jobs\ProcessOcrJob::dispatch($path, $driverName, $options);
    }
}
