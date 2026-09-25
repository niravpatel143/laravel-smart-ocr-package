<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;

class FieldResult
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly ?float $confidence,
        public readonly string $sourceDriver = '',
        public readonly ?FieldCitation $citation = null,
    ) {}

    public function isConfident(float $threshold = 0.85): bool
    {
        if ($this->confidence === null) {
            return false;
        }
        return $this->confidence >= $threshold;
    }

    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'value'        => $this->value,
            'confidence'   => $this->confidence,
            'sourceDriver' => $this->sourceDriver,
            'citation'     => $this->citation?->toArray(),
        ];
    }
}
