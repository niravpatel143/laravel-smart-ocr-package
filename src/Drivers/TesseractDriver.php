<?php

namespace LaravelSmartOCR\Drivers;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Exceptions\OCRException;

/**
 * Calls the Tesseract binary directly via exec() — no third-party PHP wrapper needed.
 * Tesseract must be installed on the OS separately.
 */
class TesseractDriver implements OCRDriver
{
    protected array $config;

    private const SUPPORTED_FORMATS = ['jpg', 'jpeg', 'png', 'tiff', 'bmp'];

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
        $startTime    = microtime(true);
        $binary       = $this->resolveBinary();
        $createdFiles = []; // Track files this driver created so we only delete those
        $imagePath    = $this->prepareDocument($document, $createdFiles);

        try {
            $outputBase = sys_get_temp_dir() . '/ocr_out_' . bin2hex(random_bytes(8));
            $lang       = $options['language'] ?? $this->config['language'] ?? 'eng';
            $psm        = $options['psm']      ?? $this->config['psm']      ?? 3;

            $timeout = max(1, (int) ($this->config['timeout'] ?? 60));

            $cmd = sprintf(
                '%s %s %s -l %s --psm %d',
                escapeshellarg($binary),
                escapeshellarg($imagePath),
                escapeshellarg($outputBase),
                escapeshellarg($lang),
                (int) $psm
            );

            // Use proc_open with a timeout to prevent hanging indefinitely
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes);

            if (!is_resource($proc)) {
                throw new OCRException("Failed to start Tesseract process.");
            }

            $stderr    = '';
            $startedAt = time();
            $exitCode  = -1;

            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if ((time() - $startedAt) >= $timeout) {
                    proc_terminate($proc);
                    throw new OCRException("Tesseract timed out after {$timeout} seconds.");
                }
                usleep(100000); // 100ms poll
            }

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            $cmdOutput = $stderr ? [$stderr] : [];

            $textFile = $outputBase . '.txt';

            if ($exitCode !== 0 || !file_exists($textFile)) {
                $stderr = implode(' ', $cmdOutput);

                // Language pack not installed — give a clear, actionable message
                if (str_contains($stderr, 'Failed loading language') || str_contains($stderr, 'traineddata')) {
                    throw new OCRException(
                        "Tesseract language pack '{$lang}' is not installed.\n" .
                        "Download '{$lang}.traineddata' from https://github.com/tesseract-ocr/tessdata/raw/main/{$lang}.traineddata " .
                        "and place it in C:\\Program Files\\Tesseract-OCR\\tessdata\\ (Windows) or /usr/share/tesseract-ocr/4.00/tessdata/ (Linux).\n" .
                        "Only English (eng) is installed by default."
                    );
                }

                throw new OCRException("Tesseract failed (exit {$exitCode}): {$stderr}");
            }

            $text = file_get_contents($textFile);

            return [
                'text'       => $text,
                'confidence' => null,
                'bounds'     => [],
                'metadata'   => [
                    'engine'          => 'tesseract',
                    'language'        => $lang,
                    'psm'             => $psm,
                    'processing_time' => microtime(true) - $startTime,
                ],
            ];
        } finally {
            // Only delete files this driver created itself (tracked in $createdFiles)
            if (isset($textFile) && file_exists($textFile)) {
                @unlink($textFile);
            }
            foreach ($createdFiles as $createdFile) {
                if (file_exists($createdFile)) {
                    @unlink($createdFile);
                }
            }
        }
    }

    public function extractTable($document, array $options = []): array
    {
        $options['psm'] = $options['psm'] ?? 6;
        $result = $this->extract($document, $options);

        $table = [];
        foreach (explode("\n", $result['text']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $table[] = array_map('trim', preg_split('/\s{2,}|\t/', $line));
        }

        return [
            'table'    => $table,
            'raw_text' => $result['text'],
            'metadata' => $result['metadata'],
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        throw new OCRException("TesseractDriver cannot extract barcodes. Use ClaudeVisionDriver or OpenAIVisionDriver.");
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException("TesseractDriver cannot extract QR codes. Use ClaudeVisionDriver or OpenAIVisionDriver.");
    }

    public function getSupportedLanguages(): array
    {
        return [
            'eng' => 'English',   'spa' => 'Spanish',  'fra' => 'French',
            'deu' => 'German',    'ita' => 'Italian',   'por' => 'Portuguese',
            'hin' => 'Hindi',     'ara' => 'Arabic',    'rus' => 'Russian',
            'jpn' => 'Japanese',  'kor' => 'Korean',
            'chi_sim' => 'Chinese Simplified', 'chi_tra' => 'Chinese Traditional',
        ];
    }

    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function resolveBinary(): string
    {
        $binary = $this->config['binary'] ?? '';

        // Try config path
        if ($binary && file_exists($binary)) {
            return $binary;
        }

        // Try PATH
        $found = trim(shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where tesseract 2>NUL' : 'which tesseract 2>/dev/null') ?? '');
        if ($found) {
            return $found;
        }

        // Common Windows install path
        $win = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
        if (file_exists($win)) {
            return $win;
        }

        throw new OCRException(
            "Tesseract binary not found. Install from https://github.com/UB-Mannheim/tesseract/wiki " .
            "or set TESSERACT_BINARY in your .env file."
        );
    }

    /**
     * @param array $createdFiles Pass-by-reference list of temp files this driver creates.
     *                            Only these files will be deleted in the finally block.
     */
    private function prepareDocument(string $document, array &$createdFiles): string
    {
        if (!file_exists($document)) {
            throw new OCRException("File not found: {$document}");
        }

        // Use finfo to detect actual type — uploaded files have .tmp extension
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($document);

        if ($mime === 'application/pdf') {
            return $this->convertPdfToImage($document, $createdFiles);
        }

        if (str_starts_with($mime, 'image/')) {
            // Return the original path — do NOT add to $createdFiles (we did not create it)
            return $document;
        }

        throw new OCRException("Unsupported format for Tesseract (detected: {$mime}). Supported: images and PDF.");
    }

    private function convertPdfToImage(string $pdfPath, array &$createdFiles): string
    {
        // Use Ghostscript if available — no PHP package needed
        $gs  = PHP_OS_FAMILY === 'Windows' ? 'gswin64c' : 'gs';
        $out = sys_get_temp_dir() . '/ocr_pdf_' . bin2hex(random_bytes(8)) . '.png';

        $cmd = sprintf(
            '%s -dNOPAUSE -dBATCH -sDEVICE=png16m -r300 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s 2>&1',
            escapeshellarg($gs),
            escapeshellarg($out),
            escapeshellarg($pdfPath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($out)) {
            throw new OCRException(
                "Cannot convert PDF to image. Install Ghostscript or use PdfTextDriver for digital PDFs. " .
                "Error: " . implode(' ', $output)
            );
        }

        // Track this temp file so the driver deletes it during cleanup
        $createdFiles[] = $out;

        return $out;
    }
}
