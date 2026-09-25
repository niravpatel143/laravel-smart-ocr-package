<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;

class FieldCitation
{
    public function __construct(
        public readonly int $page = 1,
        public readonly ?array $bbox = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'page' => $this->page,
            'bbox' => $this->bbox,
        ], fn($v) => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            page: (int)($data['page'] ?? 1),
            bbox: $data['bbox'] ?? null,
        );
    }
}
