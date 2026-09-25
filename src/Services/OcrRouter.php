<?php declare(strict_types=1);
namespace LaravelSmartOCR\Services;
use LaravelSmartOCR\Exceptions\BudgetExceededException;

class OcrRouter
{
    /**
     * Order drivers by estimated cost (cheapest first).
     * Returns driver names, free drivers first.
     */
    public function cheapestOrder(array $available): array
    {
        $free = ['tesseract', 'pdf', 'openai_compatible'];
        $free = array_intersect($free, $available);
        $paid = array_diff($available, $free);

        usort($paid, function ($a, $b) {
            $pa = (float)config("smart-ocr.pricing.{$a}.per_page", '999');
            $pb = (float)config("smart-ocr.pricing.{$b}.per_page", '999');
            return $pa <=> $pb;
        });

        return array_values(array_merge($free, $paid));
    }

    /**
     * Order drivers by quality (most accurate first based on config weight).
     */
    public function bestOrder(array $available): array
    {
        $quality = config('smart-ocr.routing.quality_order', ['aws','google','azure','mistral','claude','openai','openai_compatible','tesseract','pdf']);
        return array_values(array_intersect($quality, $available));
    }

    /**
     * Check if estimated cost exceeds budget limit.
     */
    public function checkBudget(string $driver, int $pages): void
    {
        $maxCost = (float)config('smart-ocr.routing.max_cost_per_document', '0');
        if ($maxCost <= 0) return; // No limit

        $pricePerPage = (float)config("smart-ocr.pricing.{$driver}.per_page", '0');
        $estimated = $pricePerPage * $pages;
        if ($estimated > $maxCost) {
            throw new BudgetExceededException(
                "Estimated cost for driver '{$driver}' ({$estimated} USD) exceeds max_cost_per_document ({$maxCost} USD)."
            );
        }
    }
}
