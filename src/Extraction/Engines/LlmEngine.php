<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction\Engines;
use Illuminate\Support\Facades\Http;
use LaravelSmartOCR\Results\OcrResult;

class LlmEngine
{
    public function __construct(private readonly array $config = []) {}

    public function extract(OcrResult $result, array $schema): array
    {
        $text = $result->text();
        if (empty(trim($text))) return [];

        $endpoint = $this->config['endpoint'] ?? config('smart-ocr.drivers.openai.base_url', 'https://api.openai.com/v1');
        $apiKey   = $this->config['api_key']  ?? config('smart-ocr.drivers.openai.api_key', '');
        $model    = $this->config['model']    ?? config('smart-ocr.drivers.openai.model', 'gpt-4o-mini');

        if (empty($apiKey)) return [];

        $prompt = $this->buildPrompt($text, $schema);

        $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->post("{$endpoint}/chat/completions", [
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0,
            ]);

        if (!$response->ok()) return [];

        $content = $response->json('choices.0.message.content', '');
        // Strip markdown code fences
        $content = (string)preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
        $data = json_decode(trim($content), true);
        return is_array($data) ? $data : [];
    }

    private function buildPrompt(string $text, array $schema): string
    {
        $fields = implode("\n", array_map(
            fn($name, $def) => "- {$name}: " . ($def['description'] ?? $name),
            array_keys($schema['properties'] ?? []),
            array_values($schema['properties'] ?? [])
        ));
        return "Extract the following fields from the document text. Return only valid JSON.\n\nFields:\n{$fields}\n\nDocument:\n{$text}\n\nReturn a JSON object with null for missing values.";
    }
}
