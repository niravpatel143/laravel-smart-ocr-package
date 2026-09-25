<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction\Attributes;
use Attribute;
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ListOf
{
    public function __construct(
        public readonly string $class,
    ) {}
}
