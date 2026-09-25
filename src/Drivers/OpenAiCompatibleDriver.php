<?php declare(strict_types=1);
namespace LaravelSmartOCR\Drivers;
use Illuminate\Support\Facades\Http;
use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Exceptions\AuthenticationException;
use LaravelSmartOCR\Exceptions\RateLimitException;
use LaravelSmartOCR\Exceptions\UnsupportedDocumentException;
use LaravelSmartOCR\Results\OcrResult;

/**
 * Generic driver for any OpenAI-compatible vision endpoint.
 * Works with local models (Ollama, LM Studio) and self-hosted deployments.
 * Free when using a local model.
 */
class OpenAiCompatibleDriver implements OCRDriver
{
    public function read(mixed $source, array $options = []): OcrResult
    {
        $path     = is_string($source) ? $source : (method_exists($source, 'path') ? $source->path() : (string)$source);
        $endpoint = $options['endpoint'] ?? config('smart-ocr.drivers.openai_compatible.endpoint', 'http://localhost:11434/v1');
        $apiKey   = $options['api_key']  ?? config('smart-ocr.drivers.openai_compatible.api_key', 'ollama');
        $model    = $options['model']    ?? config('smart-ocr.drivers.openai_compatible.model', 'llava');
        $prompt   = $options['prompt']   ?? config('smart-ocr.drivers.openai_compatible.prompt', 'Extract all text from this image. Return only the extracted text, preserving layout.');

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path) ?: 'image/jpeg';
        if (str_contains($mime, 'pdf')) {
            throw new UnsupportedDocumentException('OpenAI-compatible driver: PDF not supported. Convert to image first.');
        }

        $base64  = base64_encode((string)file_get_contents($path));
        $dataUri = "data:{$mime};base64,{$base64}";

        $start    = microtime(true);
        $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->timeout((int)config('smart-ocr.drivers.openai_compatible.timeout', 60))
            ->post("{$endpoint}/chat/completions", [
                'model'    => $model,
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text',      'text'      => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                    ],
                ]],
                'temperature' => 0,
            ]);
        $durationMs = (int)((microtime(true) - $start) * 1000);

        if ($response->status() === 401) throw new AuthenticationException('OpenAI-compatible: authentication failed.');
        if ($response->status() === 429) throw new RateLimitException('OpenAI-compatible: rate limit exceeded.');

        $text   = $response->ok() ? (string)$response->json('choices.0.message.content', '') : '';
        $usage  = $response->json('usage', []);

        return OcrResult::fromArray([
            'success'    => $response->ok(),
            'provider'   => 'openai_compatible',
            'text'       => $text,
            'confidence' => null,
            'metadata'   => [
                'duration_ms'   => $durationMs,
                'model'         => $model,
                'endpoint'      => $endpoint,
                'input_tokens'  => $usage['prompt_tokens'] ?? null,
                'output_tokens' => $usage['completion_tokens'] ?? null,
            ],
            'raw' => $response->ok() ? $response->json() : [],
            'errors' => $response->ok() ? [] : ["HTTP {$response->status()}"],
        ]);
    }

    public function extract(string $path, array $options = []): array { return $this->read($path, $options)->toArray(); }
    public function extractText(string $path, array $options = []): string { return $this->read($path, $options)->text(); }
    public function extractTable(string $path, array $options = []): array { return []; }
    public function extractBarcode(string $path): array { return []; }
    public function extractQRCode(string $path): array { return []; }
    public function getSupportedLanguages(): array { return ['auto']; }
    public function getSupportedFormats(): array { return ['image/jpeg','image/png','image/webp','image/gif']; }
}
