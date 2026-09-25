<?php declare(strict_types=1);

namespace LaravelSmartOCR\Results;

class OcrResult
{
    public function __construct(private readonly array $data) {}

    /** Providers that do not expose a genuine confidence score. */
    private const NO_CONFIDENCE_PROVIDERS = ['tesseract', 'claude', 'openai', 'pdf'];

    public static function fromArray(array $data): self
    {
        return new self(array_merge([
            'success'        => true,
            'provider'       => 'unknown',
            'text'           => '',
            'confidence'     => null,
            'pages'          => [],
            'lines'          => [],
            'words'          => [],
            'blocks'         => [],
            'tables'         => [],
            'fields'         => [],
            'metadata'       => [],
            'raw'            => [],
            'errors'         => [],
            'schema_version' => 1,
        ], $data));
    }

    /** Wrap a legacy extract() array into an OcrResult */
    public static function fromLegacy(array $legacy, string $provider): self
    {
        return self::fromArray([
            'success'    => true,
            'provider'   => $provider,
            'text'       => $legacy['text'] ?? '',
            'confidence' => in_array($provider, self::NO_CONFIDENCE_PROVIDERS, true) ? null : ($legacy['confidence'] ?? null),
            'blocks'     => $legacy['bounds'] ?? [],
            'metadata'   => $legacy['metadata'] ?? [],
            'raw'        => $legacy,
        ]);
    }

    public function text(): string      { return $this->data['text'] ?? ''; }
    public function confidence(): ?float
    {
        $value    = $this->data['confidence'] ?? null;
        $provider = $this->data['provider'] ?? '';
        if ($value === null || in_array($provider, self::NO_CONFIDENCE_PROVIDERS, true)) {
            return null;
        }
        return (float) $value;
    }
    public function provider(): string    { return $this->data['provider'] ?? 'unknown'; }
    public function isSuccessful(): bool  { return (bool)($this->data['success'] ?? false); }
    public function pages(): array        { return $this->data['pages'] ?? []; }
    public function lines(): array        { return $this->data['lines'] ?? []; }
    public function words(): array        { return $this->data['words'] ?? []; }
    public function blocks(): array       { return $this->data['blocks'] ?? []; }
    public function tables(): array       { return $this->data['tables'] ?? []; }
    public function fields(): array       { return $this->data['fields'] ?? []; }
    public function metadata(): array     { return $this->data['metadata'] ?? []; }
    public function raw(): array          { return $this->data['raw'] ?? []; }
    public function errors(): array       { return $this->data['errors'] ?? []; }
    public function schemaVersion(): int  { return (int)($this->data['schema_version'] ?? 1); }

    public function toMarkdown(): string
    {
        return (new \LaravelSmartOCR\Output\MarkdownRenderer())->render($this);
    }

    /** @return \LaravelSmartOCR\Output\Chunk[] */
    public function chunks(int $maxTokens = 500, int $overlap = 50): array
    {
        return (new \LaravelSmartOCR\Output\Chunker())->chunk($this->toMarkdown(), $maxTokens, $overlap);
    }

    public function toArray(): array
    {
        return [
            'success'        => $this->isSuccessful(),
            'provider'       => $this->provider(),
            'text'           => $this->text(),
            'confidence'     => $this->confidence(),
            'pages'          => $this->pages(),
            'lines'          => $this->lines(),
            'words'          => $this->words(),
            'blocks'         => $this->blocks(),
            'tables'         => $this->tables(),
            'fields'         => $this->fields(),
            'metadata'       => $this->metadata(),
            'raw'            => $this->raw(),
            'errors'         => $this->errors(),
            'schema_version' => $this->schemaVersion(),
        ];
    }
}
