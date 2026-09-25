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

class GoogleVisionDriver implements OCRDriver, CloudOcrCapable
{
    use Retryable;

    private const API_BASE        = 'https://vision.googleapis.com/v1';
    private const SUPPORTED_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'pdf'];
    private const MAX_FILE_SIZE   = 20 * 1024 * 1024; // 20 MB

    private string $apiKey;
    private ?string $credentialsPath;
    private string $projectId;
    private ?string $accessToken = null;
    private int $timeout;
    private bool $verifySsl;

    public function __construct(private readonly array $config = [])
    {
        $this->apiKey          = $config['api_key'] ?? '';
        $this->credentialsPath = $config['credentials'] ?? null;
        $this->projectId       = $config['project_id'] ?? '';
        $this->timeout         = (int) ($config['timeout'] ?? 60);
        $this->verifySsl       = (bool) ($config['ssl_verify'] ?? true);

        if (empty($this->apiKey) && empty($this->credentialsPath)) {
            throw ConfigurationException::missingKey('google', 'api_key or credentials');
        }
    }

    // ──────────────────────────────────────────────────────── CloudOcrCapable ──

    public function read(mixed $document, array $options = []): OcrResult
    {
        return $this->withRetry(function () use ($document, $options) {
            $filePath = $this->resolveFilePath($document);
            $this->assertSupportedFormat($filePath);
            $this->assertFileSize($filePath);

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType  = $this->detectMimeType($filePath);

            if ($extension === 'pdf') {
                return $this->processPdf($filePath, $options);
            }
            return $this->processImage($filePath, $mimeType, $options);
        });
    }

    // ─────────────────────────────────────────────────────── OCRDriver ──

    public function extract($document, array $options = []): array
    {
        $result = $this->read($document, $options);
        return [
            'text'       => $result->text(),
            'confidence' => $result->confidence(),
            'bounds'     => $result->blocks(),
            'metadata'   => $result->metadata(),
        ];
    }

    public function extractText($document, array $options = []): array
    {
        return $this->extract($document, $options);
    }

    public function extractTable($document, array $options = []): array
    {
        $result = $this->read($document, $options);
        return [
            'table'    => $result->tables(),
            'raw_text' => $result->text(),
            'metadata' => $result->metadata(),
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        $filePath = $this->resolveFilePath($document);
        $this->callVisionApi($filePath, $this->detectMimeType($filePath), ['DOCUMENT_TEXT_DETECTION']);
        return [
            'barcodes' => [],
            'raw_text' => '',
            'metadata' => ['engine' => 'google-vision', 'note' => 'Use extractText for text; barcode detection not normalized'],
        ];
    }

    public function extractQRCode($document, array $options = []): array
    {
        return $this->extractBarcode($document, $options);
    }

    public function getSupportedLanguages(): array
    {
        return ['auto' => 'Auto-detect (100+ languages supported)'];
    }

    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    // ─────────────────────────────────────────────────────── Internal ──

    private function processImage(string $filePath, string $mimeType, array $options): OcrResult
    {
        $response = $this->callVisionApi($filePath, $mimeType, ['DOCUMENT_TEXT_DETECTION']);
        return $this->normalizeImageResponse($response, $options);
    }

    private function processPdf(string $filePath, array $options): OcrResult
    {
        // Use annotateFile for PDFs (inline, up to 5 pages)
        $content = base64_encode(file_get_contents($filePath));
        $payload = [
            'requests' => [[
                'inputConfig' => [
                    'content'  => $content,
                    'mimeType' => 'application/pdf',
                ],
                'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                'pages'    => range(1, min(5, $this->getPdfPageCount($filePath))),
            ]],
        ];
        $raw = $this->apiPost('/files:annotate', $payload);
        return $this->normalizePdfResponse($raw, $options);
    }

    private function callVisionApi(string $filePath, string $mimeType, array $features): array
    {
        $content = base64_encode(file_get_contents($filePath));
        $payload = [
            'requests' => [[
                'image'    => ['content' => $content],
                'features' => array_map(fn($f) => ['type' => $f, 'maxResults' => 1000], $features),
            ]],
        ];
        return $this->apiPost('/images:annotate', $payload);
    }

    private function normalizeImageResponse(array $raw, array $options): OcrResult
    {
        $response = $raw['responses'][0] ?? [];
        $this->assertNoError($response);

        $fullText = $response['fullTextAnnotation'] ?? [];
        $text     = $fullText['text'] ?? '';

        $pages  = [];
        $lines  = [];
        $words  = [];
        $blocks = [];

        foreach ($fullText['pages'] ?? [] as $pageIdx => $page) {
            $pageLines  = [];
            $pageBlocks = [];
            foreach ($page['blocks'] ?? [] as $block) {
                $blockWords = [];
                $blockText  = '';
                foreach ($block['paragraphs'] ?? [] as $para) {
                    foreach ($para['words'] ?? [] as $word) {
                        $wordText  = implode('', array_map(fn($s) => $s['text'] ?? '', $word['symbols'] ?? []));
                        $wordConf  = ($word['confidence'] ?? 0.95);
                        $wordBox   = BoundingBoxNormalizer::fromVertices(array_map(
                            fn($v) => ['x' => $v['x'] ?? 0, 'y' => $v['y'] ?? 0],
                            $word['boundingBox']['vertices'] ?? []
                        ));
                        $wordData     = ['text' => $wordText, 'confidence' => $wordConf, 'bounding_box' => $wordBox];
                        $words[]      = $wordData;
                        $blockWords[] = $wordData;
                        $blockText   .= $wordText . ' ';
                    }
                }
                $blockBox   = BoundingBoxNormalizer::fromVertices(array_map(
                    fn($v) => ['x' => $v['x'] ?? 0, 'y' => $v['y'] ?? 0],
                    $block['boundingBox']['vertices'] ?? []
                ));
                $blockData    = ['text' => trim($blockText), 'confidence' => $block['confidence'] ?? 0.95, 'bounding_box' => $blockBox, 'words' => $blockWords];
                $blocks[]     = $blockData;
                $pageBlocks[] = $blockData;
            }
            // Build lines from textAnnotations if available
            foreach ($response['textAnnotations'] ?? [] as $i => $ann) {
                if ($i === 0) continue; // first annotation is full text
                $lineBox  = BoundingBoxNormalizer::fromVertices(array_map(
                    fn($v) => ['x' => $v['x'] ?? 0, 'y' => $v['y'] ?? 0],
                    $ann['boundingPoly']['vertices'] ?? []
                ));
                $lineData    = ['text' => $ann['description'] ?? '', 'confidence' => 0.95, 'bounding_box' => $lineBox];
                $lines[]     = $lineData;
                $pageLines[] = $lineData;
            }
            $pageConf = $page['confidence'] ?? $this->averageConfidence($words);
            $pages[]  = [
                'page'       => $pageIdx + 1,
                'text'       => $text,
                'confidence' => $pageConf,
                'blocks'     => $pageBlocks,
                'lines'      => $pageLines,
            ];
        }

        $confidence = $this->averageConfidence($words) ?: 0.95;

        return OcrResult::fromArray([
            'success'    => true,
            'provider'   => 'google',
            'text'       => $text,
            'confidence' => $confidence,
            'pages'      => $pages,
            'lines'      => $lines,
            'words'      => $words,
            'blocks'     => $blocks,
            'tables'     => [],
            'fields'     => [],
            'metadata'   => [
                'engine'   => 'google-vision',
                'language' => $options['language'] ?? 'auto',
            ],
            'raw'        => $raw,
            'errors'     => [],
        ]);
    }

    private function normalizePdfResponse(array $raw, array $options): OcrResult
    {
        $allText  = '';
        $pages    = [];
        $allLines = [];
        $allWords = [];

        foreach ($raw['responses'] ?? [] as $pageResponse) {
            foreach ($pageResponse['responses'] ?? [] as $response) {
                $fullText = $response['fullTextAnnotation'] ?? [];
                $pageText = $fullText['text'] ?? '';
                $allText .= $pageText . "\n";

                $pageLines = [];
                foreach ($fullText['pages'][0]['blocks'] ?? [] as $block) {
                    foreach ($block['paragraphs'] ?? [] as $para) {
                        $lineText = '';
                        foreach ($para['words'] ?? [] as $word) {
                            $wordText   = implode('', array_map(fn($s) => $s['text'] ?? '', $word['symbols'] ?? []));
                            $lineText  .= $wordText . ' ';
                            $allWords[] = ['text' => $wordText, 'confidence' => $word['confidence'] ?? 0.95, 'bounding_box' => BoundingBoxNormalizer::empty()];
                        }
                        $lineData    = ['text' => trim($lineText), 'confidence' => 0.95, 'bounding_box' => BoundingBoxNormalizer::empty()];
                        $pageLines[] = $lineData;
                        $allLines[]  = $lineData;
                    }
                }
                $pages[] = [
                    'page'       => count($pages) + 1,
                    'text'       => $pageText,
                    'confidence' => 0.95,
                    'blocks'     => [],
                    'lines'      => $pageLines,
                ];
            }
        }

        return OcrResult::fromArray([
            'success'    => true,
            'provider'   => 'google',
            'text'       => trim($allText),
            'confidence' => 0.95,
            'pages'      => $pages,
            'lines'      => $allLines,
            'words'      => $allWords,
            'blocks'     => [],
            'tables'     => [],
            'fields'     => [],
            'metadata'   => ['engine' => 'google-vision', 'language' => $options['language'] ?? 'auto'],
            'raw'        => $raw,
            'errors'     => [],
        ]);
    }

    private function apiPost(string $endpoint, array $payload): array
    {
        $token = $this->getAuthToken();
        $url   = self::API_BASE . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_HTTPHEADER     => array_values(array_filter([
                'Content-Type: application/json',
                $this->apiKey ? "x-goog-api-key: {$this->apiKey}" : null,
                $token ? "Authorization: Bearer {$token}" : null,
            ])),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw OcrTimeoutException::forProvider('google', $this->timeout);
        }

        $decoded = json_decode($body, true) ?? [];

        if ($code === 401 || $code === 403) {
            throw AuthenticationException::invalidCredentials('google');
        }
        if ($code === 429) {
            throw new RateLimitException('google', 60);
        }
        if ($code >= 400) {
            $msg = $decoded['error']['message'] ?? "HTTP {$code}";
            throw ProviderException::fromProviderError('google', $msg, $code);
        }

        return $decoded;
    }

    private function getAuthToken(): ?string
    {
        if (!empty($this->credentialsPath) && file_exists($this->credentialsPath)) {
            // Try Google SDK if available
            if (class_exists(\Google\Auth\Credentials\ServiceAccountCredentials::class)) {
                if ($this->accessToken === null) {
                    $creds = new \Google\Auth\Credentials\ServiceAccountCredentials(
                        ['https://www.googleapis.com/auth/cloud-vision'],
                        json_decode(file_get_contents($this->credentialsPath), true)
                    );
                    $token             = $creds->fetchAuthToken();
                    $this->accessToken = $token['access_token'] ?? null;
                }
                return $this->accessToken;
            }
        }
        return null; // Fall back to API key auth
    }

    private function assertNoError(array $response): void
    {
        if (isset($response['error'])) {
            $code = $response['error']['code'] ?? 0;
            $msg  = $response['error']['message'] ?? 'Unknown error';
            if ($code === 401 || $code === 403) {
                throw AuthenticationException::invalidCredentials('google');
            }
            throw ProviderException::fromProviderError('google', $msg, (int)$code);
        }
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
        throw new \InvalidArgumentException('Unsupported document type: ' . get_class($document));
    }

    private function assertSupportedFormat(string $filePath): void
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED_FORMATS, true)) {
            throw UnsupportedDocumentException::forFormat('google', $ext);
        }
    }

    private function assertFileSize(string $filePath): void
    {
        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw ProviderException::fromProviderError('google', 'File exceeds 20 MB limit');
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

    private function getPdfPageCount(string $filePath): int
    {
        // Simple page count from PDF metadata; default to 1 if unable
        try {
            $content = file_get_contents($filePath);
            preg_match_all('/\/Page\b/', $content, $matches);
            return max(1, count($matches[0]));
        } catch (\Throwable) {
            return 1;
        }
    }
}
