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

class AwsTextractDriver implements OCRDriver, CloudOcrCapable
{
    use Retryable;

    private const SUPPORTED_FORMATS = ['jpg', 'jpeg', 'png', 'pdf', 'tiff'];
    private const MAX_SYNC_SIZE     = 5 * 1024 * 1024; // 5 MB — sync limit
    private const POLL_INTERVAL     = 5;
    private const MAX_POLL_ATTEMPTS = 60;

    private string $region;
    private string $accessKey;
    private string $secretKey;
    private ?string $sessionToken;
    private ?string $s3Bucket;
    private int $timeout;

    public function __construct(private readonly array $config = [])
    {
        $this->region       = $config['region'] ?? $config['textract']['region'] ?? 'us-east-1';
        $this->accessKey    = $config['access_key'] ?? $config['key'] ?? '';
        $this->secretKey    = $config['secret_key'] ?? $config['secret'] ?? '';
        $this->sessionToken = $config['session_token'] ?? null;
        $this->s3Bucket     = $config['s3']['bucket'] ?? $config['bucket'] ?? null;
        $this->timeout      = (int)($config['timeout'] ?? 120);

        if (empty($this->accessKey) || empty($this->secretKey)) {
            throw ConfigurationException::missingKey('aws', 'access_key and secret_key');
        }
    }

    // ──────────────────────────────────────────────────── CloudOcrCapable ──

    public function read(mixed $document, array $options = []): OcrResult
    {
        return $this->withRetry(function () use ($document, $options) {
            $filePath = $this->resolveFilePath($document);
            $this->assertSupportedFormat($filePath);

            $fileSize  = filesize($filePath);
            $isPdf     = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf';
            $pageCount = $isPdf ? $this->getPdfPageCount($filePath) : 1;

            // AWS Textract sync accepts single-page documents only for PDFs
            // Multi-page PDFs or files over 5 MB must use the async (S3) flow
            if ($fileSize > self::MAX_SYNC_SIZE || ($isPdf && $pageCount > 1)) {
                return $this->processAsync($filePath, $options);
            }
            return $this->processSync($filePath, $options);
        });
    }

    public function readFromS3(string $s3Key, array $options = []): OcrResult
    {
        if (empty($this->s3Bucket)) {
            throw ConfigurationException::missingKey('aws', 's3.bucket');
        }
        return $this->withRetry(function () use ($s3Key, $options) {
            $response = $this->callTextract('AnalyzeDocument', [
                'Document'     => ['S3Object' => ['Bucket' => $this->s3Bucket, 'Name' => $s3Key]],
                'FeatureTypes' => ['TABLES', 'FORMS'],
            ]);
            return $this->normalizeBlocks($response['Blocks'] ?? [], 'aws', $options);
        });
    }

    // ─────────────────────────────────────────────────── OCRDriver ──

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
        return ['barcodes' => [], 'raw_text' => '', 'metadata' => ['engine' => 'aws-textract', 'note' => 'Barcode not supported']];
    }

    public function extractQRCode($document, array $options = []): array
    {
        return $this->extractBarcode($document, $options);
    }

    public function getSupportedLanguages(): array
    {
        return ['auto' => 'English (primary), Spanish, German, French, Italian, Portuguese'];
    }

    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    // ──────────────────────────────────────────────────── Internal ──

    private function processSync(string $filePath, array $options): OcrResult
    {
        $ext      = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $hasTable = $ext !== 'tiff';
        $features = $hasTable ? ['TABLES', 'FORMS'] : [];
        $action   = empty($features) ? 'DetectDocumentText' : 'AnalyzeDocument';

        $params = ['Document' => ['Bytes' => base64_encode(file_get_contents($filePath))]];
        if (!empty($features)) {
            $params['FeatureTypes'] = $features;
        }

        $response = $this->callTextract($action, $params);
        return $this->normalizeBlocks($response['Blocks'] ?? [], 'aws', $options);
    }

    private function processAsync(string $filePath, array $options): OcrResult
    {
        if (empty($this->s3Bucket)) {
            throw ConfigurationException::missingKey('aws', 's3.bucket (required for multi-page PDFs and files larger than 5 MB)');
        }

        // Upload to S3 first
        $s3Key = 'smart-ocr-tmp/' . basename($filePath) . '-' . uniqid();
        $this->uploadToS3($filePath, $s3Key);

        try {
            $startResponse = $this->callTextract('StartDocumentAnalysis', [
                'DocumentLocation' => ['S3Object' => ['Bucket' => $this->s3Bucket, 'Name' => $s3Key]],
                'FeatureTypes'     => ['TABLES', 'FORMS'],
            ]);
            $jobId = $startResponse['JobId'] ?? null;
            if (!$jobId) {
                throw ProviderException::fromProviderError('aws', 'Failed to start async job');
            }

            return $this->pollJob($jobId, $options);
        } finally {
            $this->deleteFromS3($s3Key);
        }
    }

    private function pollJob(string $jobId, array $options): OcrResult
    {
        for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
            sleep(self::POLL_INTERVAL);
            $response  = $this->callTextract('GetDocumentAnalysis', ['JobId' => $jobId]);
            $status    = $response['JobStatus'] ?? 'IN_PROGRESS';

            if ($status === 'SUCCEEDED') {
                $allBlocks = $response['Blocks'] ?? [];
                $nextToken = $response['NextToken'] ?? null;
                while ($nextToken) {
                    $page      = $this->callTextract('GetDocumentAnalysis', ['JobId' => $jobId, 'NextToken' => $nextToken]);
                    $allBlocks = array_merge($allBlocks, $page['Blocks'] ?? []);
                    $nextToken = $page['NextToken'] ?? null;
                }
                return $this->normalizeBlocks($allBlocks, 'aws', $options);
            }
            if ($status === 'FAILED') {
                throw ProviderException::fromProviderError('aws', 'Async job failed: ' . ($response['StatusMessage'] ?? 'Unknown'));
            }
        }
        throw OcrTimeoutException::forProvider('aws', self::MAX_POLL_ATTEMPTS * self::POLL_INTERVAL);
    }

    private function normalizeBlocks(array $blocks, string $provider, array $options): OcrResult
    {
        $lines     = [];
        $words     = [];
        $blockData = [];
        $tables    = [];
        $fields    = [];
        $allText   = '';
        $pageMap   = [];

        // Index blocks by ID
        $blockById = [];
        foreach ($blocks as $block) {
            $blockById[$block['Id']] = $block;
        }

        foreach ($blocks as $block) {
            $type = $block['BlockType'] ?? '';
            $conf = ($block['Confidence'] ?? 100) / 100;
            $box  = BoundingBoxNormalizer::fromAwsFraction($block['Geometry']['BoundingBox'] ?? []);
            $page = $block['Page'] ?? 1;

            switch ($type) {
                case 'LINE':
                    $lineData              = ['text' => $block['Text'] ?? '', 'confidence' => $conf, 'bounding_box' => $box, 'page' => $page];
                    $lines[]               = $lineData;
                    $pageMap[$page]['lines'][] = $lineData;
                    $allText              .= ($block['Text'] ?? '') . "\n";
                    break;

                case 'WORD':
                    $wordData = ['text' => $block['Text'] ?? '', 'confidence' => $conf, 'bounding_box' => $box, 'page' => $page];
                    $words[]  = $wordData;
                    break;

                case 'TABLE':
                    $tables[] = $this->extractTable_($block, $blockById, $page);
                    break;

                case 'KEY_VALUE_SET':
                    if (($block['EntityTypes'][0] ?? '') === 'KEY') {
                        $field = $this->extractField($block, $blockById);
                        if ($field !== null) {
                            $fields[] = $field;
                        }
                    }
                    break;
            }
        }

        // Build pages
        $pages = [];
        foreach ($pageMap as $pageNum => $pageData) {
            $pages[] = [
                'page'       => $pageNum,
                'text'       => implode("\n", array_column($pageData['lines'] ?? [], 'text')),
                'confidence' => $this->averageConfidence($pageData['lines'] ?? []),
                'blocks'     => [],
                'lines'      => $pageData['lines'] ?? [],
            ];
        }

        $confidence = $this->averageConfidence($words) ?: $this->averageConfidence($lines) ?: 0.0;

        return OcrResult::fromArray([
            'success'    => true,
            'provider'   => $provider,
            'text'       => trim($allText),
            'confidence' => $confidence,
            'pages'      => $pages,
            'lines'      => $lines,
            'words'      => $words,
            'blocks'     => $blockData,
            'tables'     => $tables,
            'fields'     => $fields,
            'metadata'   => [
                'engine'   => 'aws-textract',
                'language' => $options['language'] ?? 'auto',
                'blocks'   => count($blocks),
            ],
            'raw'        => ['blocks' => $blocks],
            'errors'     => [],
        ]);
    }

    private function extractTable_(array $tableBlock, array $blockById, int $page): array
    {
        $cells  = [];
        $maxRow = 0;
        $maxCol = 0;

        foreach ($tableBlock['Relationships'] ?? [] as $rel) {
            if ($rel['Type'] === 'CHILD') {
                foreach ($rel['Ids'] as $cellId) {
                    $cell = $blockById[$cellId] ?? null;
                    if ($cell && $cell['BlockType'] === 'CELL') {
                        $row    = $cell['RowIndex'] - 1;
                        $col    = $cell['ColumnIndex'] - 1;
                        $maxRow = max($maxRow, $row);
                        $maxCol = max($maxCol, $col);

                        $cellText = '';
                        foreach ($cell['Relationships'] ?? [] as $childRel) {
                            if ($childRel['Type'] === 'CHILD') {
                                foreach ($childRel['Ids'] as $wordId) {
                                    $word      = $blockById[$wordId] ?? null;
                                    $cellText .= ($word['Text'] ?? '') . ' ';
                                }
                            }
                        }
                        $cells[$row][$col] = trim($cellText);
                    }
                }
            }
        }

        // Build headers from first row
        $headers = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $headers[] = $cells[0][$c] ?? '';
        }

        $rows = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $key       = $headers[$c] ?: "col_{$c}";
                $row[$key] = $cells[$r][$c] ?? '';
            }
            $rows[] = $row;
        }

        return ['page' => $page, 'headers' => $headers, 'rows' => $rows];
    }

    private function extractField(array $keyBlock, array $blockById): ?array
    {
        $keyText   = '';
        $valueText = '';
        $valueConf = 0.0;

        foreach ($keyBlock['Relationships'] ?? [] as $rel) {
            if ($rel['Type'] === 'CHILD') {
                foreach ($rel['Ids'] as $id) {
                    $word     = $blockById[$id] ?? null;
                    $keyText .= ($word['Text'] ?? '') . ' ';
                }
            }
            if ($rel['Type'] === 'VALUE') {
                foreach ($rel['Ids'] as $valueId) {
                    $valueBlock = $blockById[$valueId] ?? null;
                    if (!$valueBlock) {
                        continue;
                    }
                    $valueConf = ($valueBlock['Confidence'] ?? 100) / 100;
                    foreach ($valueBlock['Relationships'] ?? [] as $vRel) {
                        if ($vRel['Type'] === 'CHILD') {
                            foreach ($vRel['Ids'] as $wId) {
                                $word       = $blockById[$wId] ?? null;
                                $valueText .= ($word['Text'] ?? '') . ' ';
                            }
                        }
                    }
                }
            }
        }

        $key = trim($keyText);
        $val = trim($valueText);
        if (empty($key)) {
            return null;
        }

        return ['key' => $key, 'value' => $val, 'confidence' => $valueConf];
    }

    private function callTextract(string $action, array $params): array
    {
        if (class_exists(\Aws\Textract\TextractClient::class)) {
            return $this->callViaSdk($action, $params);
        }
        return $this->callViaHttp($action, $params);
    }

    private function callViaSdk(string $action, array $params): array
    {
        try {
            $client = new \Aws\Textract\TextractClient([
                'version'     => 'latest',
                'region'      => $this->region,
                'credentials' => array_merge(
                    ['key' => $this->accessKey, 'secret' => $this->secretKey],
                    $this->sessionToken ? ['token' => $this->sessionToken] : []
                ),
            ]);
            $method = lcfirst($action);
            $result = $client->$method($params);
            return $result->toArray();
        } catch (\Aws\Exception\AwsException $e) {
            $this->handleAwsException($e);
        }
    }

    private function callViaHttp(string $action, array $params): array
    {
        $host    = "textract.{$this->region}.amazonaws.com";
        $url     = "https://{$host}/";
        $body    = json_encode($params);
        $date    = gmdate('Ymd\THis\Z');
        $day     = substr($date, 0, 8);
        $headers = $this->signRequest('POST', $host, '/', '', $body, $date, $day, $action);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw OcrTimeoutException::forProvider('aws', $this->timeout);
        }

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode === 400 && str_contains($response, 'AccessDeniedException')) {
            throw AuthenticationException::invalidCredentials('aws');
        }
        if ($httpCode === 429) {
            throw new RateLimitException('aws', 60);
        }
        if ($httpCode >= 400) {
            throw ProviderException::fromProviderError('aws', $decoded['message'] ?? "HTTP {$httpCode}", $httpCode);
        }

        return $decoded;
    }

    private function signRequest(string $method, string $host, string $uri, string $query, string $body, string $datetime, string $date, string $action): array
    {
        $service          = 'textract';
        $region           = $this->region;
        $algorithm        = 'AWS4-HMAC-SHA256';
        $payloadHash      = hash('sha256', $body);
        $canonicalHeaders = "content-type:application/x-amz-json-1.1\nhost:{$host}\nx-amz-date:{$datetime}\nx-amz-target:Amazon_AMAZON_TEXTRACT_2018_06_27.{$action}\n";
        $signedHeaders    = 'content-type;host;x-amz-date;x-amz-target';
        $canonicalRequest = implode("\n", [$method, $uri, $query, $canonicalHeaders, $signedHeaders, $payloadHash]);
        $credentialScope  = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign     = implode("\n", [$algorithm, $datetime, $credentialScope, hash('sha256', $canonicalRequest)]);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', $service,
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true),
                    true),
                true),
            true);

        $signature     = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = "{$algorithm} Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return [
            "Authorization: {$authorization}",
            'Content-Type: application/x-amz-json-1.1',
            "X-Amz-Date: {$datetime}",
            "X-Amz-Target: Amazon_AMAZON_TEXTRACT_2018_06_27.{$action}",
        ];
    }

    private function uploadToS3(string $filePath, string $s3Key): void
    {
        if (class_exists(\Aws\S3\S3Client::class)) {
            $client = new \Aws\S3\S3Client([
                'version'     => 'latest',
                'region'      => $this->region,
                'credentials' => ['key' => $this->accessKey, 'secret' => $this->secretKey],
            ]);
            $client->putObject(['Bucket' => $this->s3Bucket, 'Key' => $s3Key, 'SourceFile' => $filePath]);
            return;
        }
        throw ConfigurationException::missingDependency('aws', 'aws/aws-sdk-php');
    }

    private function deleteFromS3(string $s3Key): void
    {
        try {
            if (class_exists(\Aws\S3\S3Client::class)) {
                $client = new \Aws\S3\S3Client([
                    'version'     => 'latest',
                    'region'      => $this->region,
                    'credentials' => ['key' => $this->accessKey, 'secret' => $this->secretKey],
                ]);
                $client->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => $s3Key]);
            }
        } catch (\Throwable) {
            // Best-effort cleanup; don't rethrow
        }
    }

    private function handleAwsException(\Aws\Exception\AwsException $e): never
    {
        $code = $e->getAwsErrorCode();
        if (in_array($code, ['AccessDeniedException', 'InvalidSignatureException', 'AuthFailure'], true)) {
            throw AuthenticationException::invalidCredentials('aws');
        }
        if ($code === 'ThrottlingException' || $code === 'ProvisionedThroughputExceededException') {
            throw new RateLimitException('aws', 60);
        }
        if ($code === 'UnsupportedDocumentException') {
            throw UnsupportedDocumentException::forFormat('aws', 'current document');
        }
        throw ProviderException::fromProviderError('aws', $e->getAwsErrorMessage() ?? $e->getMessage());
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
            throw UnsupportedDocumentException::forFormat('aws', $ext);
        }
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
        try {
            $content = file_get_contents($filePath);
            preg_match_all('/\/Type\s*\/Page\b/', $content, $matches);
            $count = count($matches[0]);
            return $count > 0 ? $count : 1;
        } catch (\Throwable) {
            return 1;
        }
    }
}
