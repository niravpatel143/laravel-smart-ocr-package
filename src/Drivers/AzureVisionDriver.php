<?php declare(strict_types=1);

namespace LaravelSmartOCR\Drivers;

use LaravelSmartOCR\Concerns\Retryable;
use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Exceptions\AuthenticationException;
use LaravelSmartOCR\Exceptions\ConfigurationException;
use LaravelSmartOCR\Exceptions\OcrTimeoutException;
use LaravelSmartOCR\Exceptions\ProviderException;
use LaravelSmartOCR\Exceptions\RateLimitException;
use LaravelSmartOCR\Exceptions\UnsupportedDocumentException;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Support\BoundingBoxNormalizer;

class AzureVisionDriver implements OCRDriver, CloudOcrCapable
{
    use Retryable;

    private const SUPPORTED_FORMATS = ['jpg', 'jpeg', 'png', 'bmp', 'tiff', 'pdf'];
    private const MAX_FILE_SIZE     = 50 * 1024 * 1024; // 50 MB
    private const POLL_INTERVAL     = 2;
    private const MAX_POLL_ATTEMPTS = 60;

    private string $endpoint;
    private string $key;
    private string $apiVersion;
    private int $timeout;
    private bool $verifySsl;

    public function __construct(private readonly array $config = [])
    {
        $this->endpoint   = rtrim($config['endpoint'] ?? '', '/');
        $this->key        = $config['key'] ?? '';
        $this->apiVersion = $config['api_version'] ?? '3.2';
        $this->timeout    = (int)($config['timeout'] ?? 60);
        $this->verifySsl  = (bool)($config['ssl_verify'] ?? true);

        if (empty($this->endpoint)) {
            throw ConfigurationException::missingKey('azure', 'endpoint');
        }
        if (empty($this->key)) {
            throw ConfigurationException::missingKey('azure', 'key');
        }
    }

    // ──────────────────────────────────────────────── CloudOcrCapable ──

    public function read(mixed $document, array $options = []): OcrResult
    {
        return $this->withRetry(function () use ($document, $options) {
            $filePath = $this->resolveFilePath($document);
            $this->assertSupportedFormat($filePath);
            $this->assertFileSize($filePath);

            $operationUrl = $this->submitReadRequest($filePath);
            $raw          = $this->pollResult($operationUrl);
            return $this->normalizeResponse($raw, $options);
        });
    }

    // ──────────────────────────────────────────────── OCRDriver ──

    public function extract($document, array $options = []): array
    {
        $result = $this->read($document, $options);
        return ['text' => $result->text(), 'confidence' => $result->confidence(), 'bounds' => $result->blocks(), 'metadata' => $result->metadata()];
    }

    public function extractText($document, array $options = []): array
    {
        return $this->extract($document, $options);
    }

    public function extractTable($document, array $options = []): array
    {
        $result = $this->read($document, $options);
        return ['table' => $result->tables(), 'raw_text' => $result->text(), 'metadata' => $result->metadata()];
    }

    public function extractBarcode($document, array $options = []): array
    {
        return ['barcodes' => [], 'raw_text' => '', 'metadata' => ['engine' => 'azure-vision', 'note' => 'Barcode not supported in Read API']];
    }

    public function extractQRCode($document, array $options = []): array
    {
        return $this->extractBarcode($document, $options);
    }

    public function getSupportedLanguages(): array
    {
        return [
            'auto' => 'Auto-detect',
            'en'   => 'English',
            'fr'   => 'French',
            'de'   => 'German',
            'es'   => 'Spanish',
            'it'   => 'Italian',
            'pt'   => 'Portuguese',
            'zh'   => 'Chinese',
            'ja'   => 'Japanese',
            'ko'   => 'Korean',
            'ar'   => 'Arabic',
        ];
    }

    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    // ──────────────────────────────────────────────── Internal ──

    private function submitReadRequest(string $filePath): string
    {
        // Use the Read 3.2 GA API — supports images and multi-page PDFs
        $url     = "{$this->endpoint}/vision/v3.2/read/analyze";
        $content = file_get_contents($filePath);
        $mime    = $this->detectMimeType($filePath);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_HTTPHEADER     => [
                "Ocp-Apim-Subscription-Key: {$this->key}",
                "Content-Type: {$mime}",
            ],
            CURLOPT_HEADER         => true,
        ]);

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr    = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw OcrTimeoutException::forProvider('azure', $this->timeout);
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $body            = substr($response, $headerSize);
        $decoded         = json_decode($body, true) ?? [];

        if ($httpCode === 202) {
            // Async — get Operation-Location for polling
            preg_match('/Operation-Location:\s*(\S+)/i', $responseHeaders, $m);
            if (empty($m[1])) {
                throw ProviderException::fromProviderError('azure', 'No Operation-Location header in 202 response');
            }
            return $m[1];
        }

        $this->handleHttpError($httpCode, $decoded);
    }

    private function pollResult(string $operationUrl): array
    {
        if (empty($operationUrl)) {
            throw ProviderException::fromProviderError('azure', 'No operation URL returned');
        }

        for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
            sleep(self::POLL_INTERVAL);

            $ch = curl_init($operationUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_HTTPHEADER     => ["Ocp-Apim-Subscription-Key: {$this->key}"],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode($body, true) ?? [];
            $status  = $decoded['status'] ?? 'running';

            if ($status === 'succeeded') {
                return $decoded;
            }
            if ($status === 'failed') {
                throw ProviderException::fromProviderError('azure', $decoded['error']['message'] ?? 'Read operation failed');
            }
        }

        throw OcrTimeoutException::forProvider('azure', self::MAX_POLL_ATTEMPTS * self::POLL_INTERVAL);
    }

    private function normalizeResponse(array $raw, array $options): OcrResult
    {
        // Handle both v3.2 and v4.0 response shapes
        $readResults = $raw['analyzeResult']['readResults']
            ?? $raw['analyzeResult']['pages']
            ?? $raw['readResults']
            ?? [];

        $allText = '';
        $pages   = [];
        $lines   = [];
        $words   = [];

        foreach ($readResults as $pageData) {
            $pageNum   = $pageData['page'] ?? (count($pages) + 1);
            $pageLines = [];
            $pageText  = '';

            foreach ($pageData['lines'] ?? [] as $line) {
                $lineText = $line['text'] ?? $line['content'] ?? '';
                $lineBox  = BoundingBoxNormalizer::fromAzureArray($line['boundingBox'] ?? $line['polygon'] ?? []);
                $lineConf = $line['confidence'] ?? 0.95;

                $lineWords = [];
                foreach ($line['words'] ?? [] as $word) {
                    $wordText  = $word['text'] ?? $word['content'] ?? '';
                    $wordBox   = BoundingBoxNormalizer::fromAzureArray($word['boundingBox'] ?? $word['polygon'] ?? []);
                    $wordConf  = $word['confidence'] ?? 0.95;
                    $wordData  = ['text' => $wordText, 'confidence' => $wordConf, 'bounding_box' => $wordBox];
                    $words[]   = $wordData;
                    $lineWords[] = $wordData;
                }

                $lineData    = ['text' => $lineText, 'confidence' => $lineConf, 'bounding_box' => $lineBox, 'words' => $lineWords, 'page' => $pageNum];
                $lines[]     = $lineData;
                $pageLines[] = $lineData;
                $pageText   .= $lineText . "\n";
                $allText    .= $lineText . "\n";
            }

            $pages[] = [
                'page'       => $pageNum,
                'text'       => trim($pageText),
                'confidence' => $this->averageConfidence($pageLines),
                'blocks'     => [],
                'lines'      => $pageLines,
            ];
        }

        $confidence = $this->averageConfidence($words) ?: $this->averageConfidence($lines) ?: 0.0;

        return OcrResult::fromArray([
            'success'    => true,
            'provider'   => 'azure',
            'text'       => trim($allText),
            'confidence' => $confidence,
            'pages'      => $pages,
            'lines'      => $lines,
            'words'      => $words,
            'blocks'     => [],
            'tables'     => [],
            'fields'     => [],
            'metadata'   => [
                'engine'   => 'azure-vision',
                'language' => $options['language'] ?? ($raw['analyzeResult']['languages'][0]['locale'] ?? 'auto'),
                'model'    => $raw['analyzeResult']['modelVersion'] ?? 'unknown',
            ],
            'raw'        => $raw,
            'errors'     => [],
        ]);
    }

    private function handleHttpError(int $code, array $decoded): never
    {
        if ($code === 401) {
            throw AuthenticationException::invalidCredentials('azure');
        }
        if ($code === 429) {
            throw new RateLimitException('azure', (int)($decoded['error']['retryAfter'] ?? 60));
        }
        $msg = $decoded['error']['message'] ?? $decoded['message'] ?? "HTTP {$code}";
        throw ProviderException::fromProviderError('azure', $msg, $code);
    }

    private function resolveFilePath(mixed $document): string
    {
        if (is_string($document)) {
            return $document;
        }
        if ($document instanceof \SplFileInfo) {
            return $document->getPathname();
        }
        if ($document instanceof \Illuminate\Http\UploadedFile) {
            return $document->getRealPath();
        }
        throw new \InvalidArgumentException('Unsupported document type');
    }

    private function assertSupportedFormat(string $filePath): void
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED_FORMATS, true)) {
            throw UnsupportedDocumentException::forFormat('azure', $ext);
        }
    }

    private function assertFileSize(string $filePath): void
    {
        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw ProviderException::fromProviderError('azure', 'File exceeds 50 MB limit');
        }
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($filePath) ?: 'application/octet-stream';
    }

    private function averageConfidence(array $items): float
    {
        if (empty($items)) {
            return 0.0;
        }
        $total = array_sum(array_column($items, 'confidence'));
        return round($total / count($items), 4);
    }
}
