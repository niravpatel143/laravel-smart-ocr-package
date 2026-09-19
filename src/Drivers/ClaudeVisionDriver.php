<?php

namespace LaravelSmartOCR\Drivers;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Exceptions\OCRException;
use LaravelSmartOCR\Http\CurlClient;

class ClaudeVisionDriver implements OCRDriver
{
    protected array $config;
    protected CurlClient $http;

    private const API_URL  = 'https://api.anthropic.com/v1/messages';
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB — Anthropic limit

    private const MIME_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->http   = new CurlClient(
            defaultHeaders: [
                'x-api-key: '         . ($config['api_key'] ?? ''),
                'anthropic-version: 2023-06-01',
            ],
            timeout:   $config['timeout']    ?? 60,
            verifySsl: $config['ssl_verify'] ?? true,
        );
    }

    public function extract($document, array $options = []): array
    {
        $startTime = microtime(true);
        $image     = $this->readImage($document);
        $prompt    = $options['prompt'] ?? $this->textPrompt($options['language'] ?? null);

        $response = $this->http->post(self::API_URL, [
            'model'      => $this->config['model'] ?? 'claude-opus-4-7',
            'max_tokens' => $this->config['max_tokens'] ?? 4096,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data']]],
                    ['type' => 'text',  'text'   => $prompt],
                ],
            ]],
        ]);

        $text = $response['content'][0]['text'] ?? '';

        return [
            'text'       => $text,
            'confidence' => 0.95,
            'bounds'     => [],
            'metadata'   => [
                'engine'          => 'claude-vision',
                'model'           => $this->config['model'] ?? 'claude-opus-4-7',
                'language'        => $options['language'] ?? 'auto',
                'processing_time' => microtime(true) - $startTime,
                'input_tokens'    => $response['usage']['input_tokens']  ?? 0,
                'output_tokens'   => $response['usage']['output_tokens'] ?? 0,
            ],
        ];
    }

    public function extractTable($document, array $options = []): array
    {
        $options['prompt'] = 'Extract all table data. Return columns separated by | and rows on new lines. Header row first. No explanation.';
        $result = $this->extract($document, $options);

        return [
            'table'    => $this->parseTable($result['text']),
            'raw_text' => $result['text'],
            'metadata' => $result['metadata'],
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        $options['prompt'] = 'Read any barcode or QR code values. Return only the decoded values, one per line.';
        $result = $this->extract($document, $options);

        return [
            'barcodes' => array_values(array_filter(explode("\n", trim($result['text'])))),
            'raw_text' => $result['text'],
            'metadata' => $result['metadata'],
        ];
    }

    public function extractQRCode($document, array $options = []): array
    {
        return $this->extractBarcode($document, $options);
    }

    public function getSupportedLanguages(): array
    {
        return ['auto' => 'Auto-detect (all languages supported)'];
    }

    public function getSupportedFormats(): array
    {
        return array_keys(self::MIME_MAP);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function textPrompt(?string $language): string
    {
        $lang = $language ? " Respond in {$language}." : '';
        return "Extract ALL text exactly as it appears in the document. Preserve layout and line breaks. Return only the text, no commentary.{$lang}";
    }

    private function readImage(string $path): array
    {
        if (!file_exists($path)) {
            throw new OCRException("File not found: {$path}");
        }

        // Detect MIME from file content, not extension (uploaded files have .tmp paths)
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);

        $mimeToExt = array_flip(self::MIME_MAP);

        if (!isset($mimeToExt[$detected])) {
            throw new OCRException(
                "ClaudeVisionDriver supports: " . implode(', ', array_unique(array_values(self::MIME_MAP))) .
                ". For PDFs use PdfTextDriver. Detected: {$detected}"
            );
        }

        $size = filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new OCRException(sprintf(
                "Image too large for Claude Vision (max 5 MB). File is %.1f MB.", $size / 1024 / 1024
            ));
        }

        return [
            'data' => base64_encode(file_get_contents($path)),
            'mime' => $detected,
        ];
    }

    private function parseTable(string $text): array
    {
        $rows = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '---') {
                continue;
            }
            $rows[] = str_contains($line, '|')
                ? array_map('trim', explode('|', trim($line, '|')))
                : [$line];
        }
        return $rows;
    }
}
