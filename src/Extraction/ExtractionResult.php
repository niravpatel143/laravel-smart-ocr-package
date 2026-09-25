<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;

class ExtractionResult
{
    /** @param FieldResult[] $fields */
    public function __construct(
        public readonly mixed $data,
        public readonly array $errors,
        public readonly array $raw,
        public readonly array $fields = [],
    ) {}

    public function field(string $name): ?FieldResult
    {
        foreach ($this->fields as $f) {
            if ($f->name === $name) return $f;
        }
        return null;
    }

    /** @return FieldResult[] */
    public function needsReview(float $threshold = 0.85): array
    {
        return array_values(array_filter($this->fields, fn($f) => $f->confidence === null || $f->confidence < $threshold));
    }

    public function isConfident(float $threshold = 0.85): bool
    {
        return empty($this->needsReview($threshold));
    }

    public function isSuccessful(): bool { return empty($this->errors); }

    public function toArray(): array
    {
        return [
            'data'   => is_object($this->data) ? (array)$this->data : $this->data,
            'errors' => $this->errors,
            'raw'    => $this->raw,
            'fields' => array_map(fn($f) => $f->toArray(), $this->fields),
        ];
    }
}
