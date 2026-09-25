<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;

class FieldCitation
{
    public function __construct(
        public readonly int $page,
        public readonly string $snippet,
        public readonly ?array $bbox,
    ) {}

    public function toArray(): array
    {
        return ['page' => $this->page, 'snippet' => $this->snippet, 'bbox' => $this->bbox];
    }
}
