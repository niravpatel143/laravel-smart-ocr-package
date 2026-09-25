<?php declare(strict_types=1);
namespace LaravelSmartOCR\Drivers;
use Illuminate\Support\Facades\Http;
use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Exceptions\AuthenticationException;
use LaravelSmartOCR\Exceptions\RateLimitException;
use LaravelSmartOCR\Exceptions\UnsupportedDocumentException;
use LaravelSmartOCR\Results\OcrResult;

/**
 * Mistral OCR driver.
 *
 * HUMAN REVIEW NEEDED: Mistral released a dedicated OCR API in early 2025.
 * Endpoint: https://api.mistral.ai/v1/ocr (POST, model: mistral-ocr-latest)
 * Docs: https://docs.mistral.ai/capabilities/document-understanding/
 * This driver uses the documented endpoint — verify against current Mistral docs.
 */
class MistralOcrDriver implements OCRDriver
{
    private const ENDPOINT = 'https://api.mistral.ai/v1/ocr';
    private const DEFAULT_MODEL = 'mistral-ocr-latest';
    private const SUPPORTED_MIME = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];

    public function read(mixed $source, array $options = []): OcrResult
    {
        $path   = is_string($source) ? $source : (method_exists($source, 'path') ? $source->path() : (string)$source);
        $apiKey = config('smart-ocr.drivers.mistral.api_key', '');
        $model  = $options['model'] ?? config('smart-ocr.drivers.mistral.model', self::DEFAULT_MODEL);

        if (empty($apiKey)) {
            throw new \LaravelSmartOCR\Exceptions\ConfigurationException('Mistral API key not configured.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path) ?: 'application/octet-stream';

        if (!in_array($mime, self::SUPPORTED_MIME, true)) {
            throw new UnsupportedDocumentException("Mistral OCR does not support MIME type: {$mime}");
        }

        $base64 = base64_encode(file_get_contents($path));
        $docType = str_contains($mime, 'pdf') ? 'document_url' : 'image_url';
        $dataUri = "data:{$mime};base64,{$base64}";

        $start    = microtime(true);
        $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->timeout((int)config('smart-ocr.drivers.mistral.timeout', 60))
            ->post(self::ENDPOINT, [
                'model' => $model,
                'document' => [
                    'type' => $docType,
                    $docType => $dataUri,
                ],
            ]);
        $durationMs = (int)((microtime(true) - $start) * 1000);

        if ($response->status() === 401) throw new AuthenticationException('Mistral: invalid API key.');
        if ($response->status() === 429) throw new RateLimitException('Mistral: rate limit exceeded.');
        if (!$response->ok()) {
            throw new \LaravelSmartOCR\Exceptions\ProviderException("Mistral OCR error: HTTP {$response->status()}");
        }

        return $this->normalize($response->json(), $durationMs);
    }

    private function normalize(array $body, int $durationMs): OcrResult
    {
        // Mistral OCR response shape (per docs): { pages: [{ index, markdown, images, dimensions }] }
        $pages   = $body['pages'] ?? [];
        $allText = implode("\n\n", array_map(fn($p) => $p['markdown'] ?? $p['text'] ?? '', $pages));

        $pageData = array_map(fn($p) => [
            'page'     => ($p['index'] ?? 0) + 1,
            'text'     => $p['markdown'] ?? $p['text'] ?? '',
            'width'    => $p['dimensions']['width'] ?? null,
            'height'   => $p['dimensions']['height'] ?? null,
        ], $pages);

        $usage  = $body['usage'] ?? [];
        $pages_processed = count($pages);

        // Cost estimate: ~$0.001 per page (config-driven)
        $pricePerPage = (float)config('smart-ocr.pricing.mistral.per_page', '0.001');
        $costAmount   = number_format($pricePerPage * $pages_processed, 6, '.', '');

        return OcrResult::fromArray([
            'success'    => true,
            'provider'   => 'mistral',
            'text'       => $allText,
            'confidence' => null, // Mistral OCR does not return per-word confidence
            'pages'      => $pageData,
            'metadata'   => [
                'duration_ms'      => $durationMs,
                'pages_processed'  => $pages_processed,
                'input_tokens'     => $usage['prompt_tokens'] ?? null,
                'output_tokens'    => $usage['completion_tokens'] ?? null,
                'model'            => $body['model'] ?? 'mistral-ocr-latest',
            ],
            'raw'  => $body,
            'cost' => [
                'amount'    => $costAmount,
                'currency'  => 'USD',
                'pages'     => $pages_processed,
                'tokens'    => ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0),
                'estimated' => true,
            ],
        ]);
    }

    public function extract(string $imagePath, array $options = []): array { return $this->read($imagePath, $options)->toArray(); }
    public function extractText(string $imagePath, array $options = []): string { return $this->read($imagePath, $options)->text(); }
    public function extractTable(string $imagePath, array $options = []): array { return []; }
    public function extractBarcode(string $imagePath): array { return []; }
    public function extractQRCode(string $imagePath): array { return []; }
    public function getSupportedLanguages(): array { return ['auto']; }
    public function getSupportedFormats(): array { return self::SUPPORTED_MIME; }
}
