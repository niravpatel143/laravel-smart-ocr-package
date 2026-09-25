<?php declare(strict_types=1);
namespace LaravelSmartOCR\Tests\Unit;
use LaravelSmartOCR\Exceptions\BudgetExceededException;
use LaravelSmartOCR\Services\OcrRouter;
use LaravelSmartOCR\Tests\TestCase;

class RoutingTest extends TestCase
{
    public function test_cheapest_order_puts_free_first(): void
    {
        $router = new OcrRouter();
        $order  = $router->cheapestOrder(['aws', 'tesseract', 'google', 'pdf']);
        $this->assertEquals('tesseract', $order[0]);
    }

    public function test_budget_guard_throws_when_exceeded(): void
    {
        config(['smart-ocr.routing.max_cost_per_document' => '0.001']);
        config(['smart-ocr.pricing.aws.per_page' => '0.0015']);
        $router = new OcrRouter();
        $this->expectException(BudgetExceededException::class);
        $router->checkBudget('aws', 2);
    }

    public function test_budget_guard_passes_when_within_limit(): void
    {
        config(['smart-ocr.routing.max_cost_per_document' => '1.00']);
        config(['smart-ocr.pricing.aws.per_page' => '0.0015']);
        $router = new OcrRouter();
        $router->checkBudget('aws', 2); // no exception
        $this->assertTrue(true);
    }
}
