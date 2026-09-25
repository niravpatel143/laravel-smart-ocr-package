<?php declare(strict_types=1);
namespace LaravelSmartOCR\Events;
use Illuminate\Foundation\Events\Dispatchable;
use LaravelSmartOCR\Extraction\ExtractionResult;

class ExtractionNeedsReview
{
    use Dispatchable;
    public function __construct(
        public readonly ExtractionResult $result,
        public readonly float $threshold,
    ) {}
}
