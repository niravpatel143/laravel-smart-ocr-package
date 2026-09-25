<?php declare(strict_types=1);
namespace LaravelSmartOCR\Events;
use Illuminate\Foundation\Events\Dispatchable;
use LaravelSmartOCR\Models\OcrReview;
class ReviewCompleted
{
    use Dispatchable;
    public function __construct(public readonly OcrReview $review) {}
}
