<?php declare(strict_types=1);
namespace LaravelSmartOCR\Testing;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Drivers\CloudOcrCapable;
use LaravelSmartOCR\Results\OcrResult;

class FakeOcrDriver implements OCRDriver, CloudOcrCapable
{
    private array $results = [];
    private array $readCalls = [];

    public function addResult(OcrResult $result): void
    {
        $this->results[] = $result;
    }

    public function read(mixed $document, array $options = []): OcrResult
    {
        $path = is_string($document)
            ? $document
            : (is_object($document) && method_exists($document, 'getPathname') ? $document->getPathname() : 'unknown');
        $this->readCalls[] = $path;

        return array_shift($this->results) ?? OcrResult::fromArray([
            'success'  => true,
            'provider' => 'fake',
            'text'     => 'Fake OCR text',
        ]);
    }

    public function extract($document, array $options = []): array
    {
        $result = $this->read($document, $options);
        return ['text' => $result->text(), 'confidence' => $result->confidence(), 'bounds' => [], 'metadata' => $result->metadata()];
    }

    public function extractText($document, array $options = []): array   { return $this->extract($document, $options); }
    public function extractTable($document, array $options = []): array  { return ['table' => [], 'raw_text' => '', 'metadata' => []]; }
    public function extractBarcode($document, array $options = []): array { return ['barcodes' => [], 'raw_text' => '', 'metadata' => []]; }
    public function extractQRCode($document, array $options = []): array  { return ['barcodes' => [], 'raw_text' => '', 'metadata' => []]; }
    public function getSupportedLanguages(): array { return ['auto' => 'All']; }
    public function getSupportedFormats(): array   { return ['jpg', 'png', 'pdf']; }

    public function assertRead(string $path): void
    {
        \PHPUnit\Framework\Assert::assertContains(
            $path, $this->readCalls,
            "Expected [{$path}] to have been read but it was not."
        );
    }

    public function assertNothingRead(): void
    {
        \PHPUnit\Framework\Assert::assertEmpty(
            $this->readCalls,
            'Expected no files to be read but ' . count($this->readCalls) . ' were.'
        );
    }

    public function readCalls(): array { return $this->readCalls; }
}
