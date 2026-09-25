<?php declare(strict_types=1);
namespace LaravelSmartOCR\Tests\Unit;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Services\PiiRedactor;
use LaravelSmartOCR\Tests\TestCase;

class PiiRedactionTest extends TestCase
{
    public function test_redacts_email(): void
    {
        $r = new PiiRedactor();
        $out = $r->redact('Contact: john@example.com for details', ['email']);
        $this->assertStringNotContainsString('john@example.com', $out);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $out);
    }

    public function test_redact_on_ocr_result(): void
    {
        $result = OcrResult::fromArray(['text' => 'Email: admin@site.com Phone: +1-800-555-1234', 'provider' => 'tesseract']);
        $redacted = $result->redact(['email', 'phone']);
        $this->assertStringNotContainsString('admin@site.com', $redacted->text());
        $this->assertStringContainsString('admin@site.com', $result->text()); // original unchanged
    }

    public function test_classify_invoice(): void
    {
        $result = OcrResult::fromArray(['text' => 'Invoice Number: 1234 Due Date: 2026-10-01 Subtotal: $100', 'provider' => 'tesseract']);
        $classification = $result->classify();
        $this->assertEquals('invoice', $classification['type']);
        $this->assertGreaterThan(0, $classification['confidence']);
    }
}
