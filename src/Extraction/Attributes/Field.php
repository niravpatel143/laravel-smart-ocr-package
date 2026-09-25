<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction\Attributes;
use Attribute;
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Field
{
    public function __construct(
        public readonly string $description,
        public readonly bool $required = false,
        public readonly ?string $format = null,
        public readonly ?string $example = null,
    ) {}
}
