<?php declare(strict_types=1);
namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Extraction\Attributes\Field;
use LaravelSmartOCR\Extraction\ExtractionService;
use LaravelSmartOCR\Extraction\SchemaBuilder;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Tests\TestCase;

final class Invoice
{
    public function __construct(
        #[Field('Invoice number')] public string $number = '',
        #[Field('Grand total, number only')] public float $total = 0.0,
        #[Field('Due date')] public ?string $dueDate = null,
    ) {}
}

class ExtractionTest extends TestCase
{
    public function test_schema_from_class(): void
    {
        $schema = SchemaBuilder::fromClass(Invoice::class);
        $this->assertArrayHasKey('number', $schema['properties']);
        $this->assertEquals('number', $schema['properties']['total']['type']);
        $this->assertEquals('Invoice number', $schema['properties']['number']['description']);
    }

    public function test_rules_engine_extracts_fields(): void
    {
        $text   = "Invoice Number: INV-2026-001\nGrand Total: \$1,250.00\nDue Date: 2026-10-01";
        $result = OcrResult::fromArray(['text' => $text, 'provider' => 'tesseract']);
        $service = new ExtractionService();
        $ext = $service->extract(Invoice::class, $result);
        $this->assertInstanceOf(Invoice::class, $ext->data);
        $this->assertStringContainsString('INV-2026-001', $ext->data->number);
        $this->assertGreaterThan(0, $ext->data->total);
    }

    public function test_array_schema(): void
    {
        $result = OcrResult::fromArray(['text' => "Order ID: 12345\nAmount: 99.99", 'provider' => 'pdf']);
        $ext = (new ExtractionService())->extract(['order_id' => 'string', 'amount' => 'number'], $result);
        $this->assertIsArray($ext->data);
    }

    public function test_no_invented_confidence(): void
    {
        $result = OcrResult::fromArray(['text' => 'Total: 100', 'provider' => 'tesseract', 'words' => []]);
        $ext = (new ExtractionService())->extract(['total' => 'number'], $result);
        foreach ($ext->fields as $f) {
            $this->assertNull($f->confidence, 'Confidence must be null when no word data — never invented');
        }
    }

    public function test_citation_found_in_words(): void
    {
        $result = OcrResult::fromArray([
            'text'     => 'INV-001 Total $500',
            'provider' => 'google',
            'words'    => [
                ['text' => 'INV-001', 'confidence' => 0.98, 'page' => 1],
            ],
        ]);
        $ext = (new ExtractionService())->extract(['number' => 'string'], $result);
        $f = $ext->field('number');
        $this->assertNotNull($f);
        $this->assertNotNull($f->citation);
        $this->assertEquals(0.98, $f->confidence);
    }
}
