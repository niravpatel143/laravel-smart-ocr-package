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

    /** Return the cost estimate DTO if set, or null. */
    public function cost(): ?\LaravelSmartOCR\Data\CostEstimate
    {
        $cost = $this->data['cost'] ?? null;
        if ($cost === null) {
            return null;
        }
        if ($cost instanceof \LaravelSmartOCR\Data\CostEstimate) {
            return $cost;
        }
        if (is_array($cost)) {
            return \LaravelSmartOCR\Data\CostEstimate::fromArray($cost);
        }
        return null;
    }

    /**
     * Return a new OcrResult with PII redacted from the text field.
     * @param string[] $types  e.g. ['email', 'phone', 'card']
     * @param string[] $custom Additional regex patterns
     */
    public function redact(array $types = [], array $custom = []): self
    {
        $redactor = new \LaravelSmartOCR\Services\PiiRedactor();
        $data     = $this->data;
        $data['text'] = $redactor->redact($data['text'] ?? '', $types, $custom);
        return new self($data);
    }

    /**
     * Classify this document into invoice/receipt/contract/id/other.
     * @param string[] $types Limit to specific types. Empty = all types.
     */
    public function classify(array $types = []): array
    {
        return (new \LaravelSmartOCR\Services\DocumentClassifier())->classify($this, $types);
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
