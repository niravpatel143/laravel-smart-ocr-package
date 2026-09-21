<?php

namespace LaravelSmartOCR\Drivers;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Exceptions\OCRException;
use Smalot\PdfParser\Parser;

class PdfTextDriver implements OCRDriver
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function extractText($document, array $options = []): array
    {
        return $this->extract($document, $options);
    }

    public function extract($document, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            if (!file_exists($document)) {
                throw new OCRException("File not found: {$document}");
            }

            if (!$this->isPdf($document)) {
                $ext = strtolower(pathinfo($document, PATHINFO_EXTENSION)) ?: 'unknown';
                throw new OCRException("PdfTextDriver only supports PDF files. Got: {$ext}. Use ClaudeVisionDriver or OpenAIVisionDriver for images.");
            }

            $parser = new Parser();
            $pdf    = $parser->parseFile($document);
            $pages  = $pdf->getPages();

            $allText  = [];
            $maxPages = $options['max_pages'] ?? ($this->config['max_pages'] ?? 100);

            foreach (array_slice($pages, 0, $maxPages) as $i => $page) {
                $pageText = $page->getText();
                if (trim($pageText) !== '') {
                    $allText[] = "--- Page " . ($i + 1) . " ---\n" . $pageText;
                }
            }

            $text = implode("\n\n", $allText);

            if (trim($text) === '') {
                throw new OCRException(
                    "No text found in PDF. This is likely a scanned PDF (image-only). " .
                    "Use ClaudeVisionDriver or OpenAIVisionDriver to extract text from scanned documents."
                );
            }

            return [
                'text'       => $text,
                'confidence' => 1.0,
                'bounds'     => [],
                'metadata'   => [
                    'engine'          => 'pdf-text',
                    'page_count'      => count($pages),
                    'pages_extracted' => min(count($pages), $maxPages),
                    'language'        => 'auto',
                    'processing_time' => microtime(true) - $startTime,
                ],
            ];
        } catch (OCRException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new OCRException("PDF text extraction failed: " . $e->getMessage());
        }
    }

    public function extractTable($document, array $options = []): array
    {
        $result = $this->extract($document, $options);
        $table  = [];

        foreach (explode("\n", $result['text']) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '---')) {
                continue;
            }
            $cells   = preg_split('/\s{2,}|\t/', $line);
            $table[] = array_map('trim', $cells);
        }

        return [
            'table'    => $table,
            'raw_text' => $result['text'],
            'metadata' => $result['metadata'],
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        throw new OCRException("PdfTextDriver cannot extract barcodes. Use ClaudeVisionDriver or OpenAIVisionDriver.");
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException("PdfTextDriver cannot extract QR codes. Use ClaudeVisionDriver or OpenAIVisionDriver.");
    }

    private function isPdf(string $path): bool
    {
        $handle = fopen($path, 'rb');
        $header = fread($handle, 4);
        fclose($handle);
        return $header === '%PDF';
    }

    public function getSupportedLanguages(): array
    {
        return ['auto' => 'Auto-detect from PDF metadata'];
    }

    public function getSupportedFormats(): array
    {
        return ['pdf'];
    }
}
