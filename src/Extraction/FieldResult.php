<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;

class FieldResult
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly ?float $confidence,
        public readonly ?FieldCitation $citation,
        public readonly string $sourceDriver,
    ) {}

    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'value'      => $this->value,
            'confidence' => $this->confidence,
            'citation'   => $this->citation?->toArray(),
            'driver'     => $this->sourceDriver,
        ];
    }
}
