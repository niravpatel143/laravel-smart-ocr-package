<?php declare(strict_types=1);

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Testing\SmartOCRFake;
use LaravelSmartOCR\Tests\TestCase;

class SmartOCRFakeTest extends TestCase
{
    public function test_records_reads(): void
    {
        $fake = new SmartOCRFake();
        $fake->recordRead('/path/to/file.pdf', 'google');
        $fake->assertRead('/path/to/file.pdf');
        $fake->assertReadCount(1);
    }

    public function test_assert_nothing_read_passes_when_no_reads(): void
    {
        $fake = new SmartOCRFake();
        $fake->assertNothingRead();
    }

    public function test_returns_configured_result(): void
    {
        $expected = OcrResult::fromArray(['text' => 'Invoice #1234', 'provider' => 'google']);
        $fake     = new SmartOCRFake($expected);
        $result   = $fake->recordRead('/file.pdf', 'google');
        $this->assertEquals('Invoice #1234', $result->text());
    }

    public function test_sequence_returns_results_in_order(): void
    {
        $fake = new SmartOCRFake();
        $fake->sequence(
            OcrResult::fromArray(['text' => 'first',  'provider' => 'google']),
            OcrResult::fromArray(['text' => 'second', 'provider' => 'google'])
        );
        $this->assertEquals('first',  $fake->recordRead('/a.pdf', 'google')->text());
        $this->assertEquals('second', $fake->recordRead('/b.pdf', 'google')->text());
    }

    public function test_prevent_stray_requests_throws(): void
    {
        $fake = new SmartOCRFake();
        $fake->preventStrayRequests();
        $this->expectException(\RuntimeException::class);
        $fake->recordRead('/file.pdf', 'google');
    }

    public function test_assert_used_driver(): void
    {
        $fake = new SmartOCRFake();
        $fake->recordRead('/file.pdf', 'tesseract');
        $fake->assertUsedDriver('tesseract');
    }

    public function test_assert_read_with_closure(): void
    {
        $fake = new SmartOCRFake();
        $fake->recordRead('/invoice.pdf', 'aws');
        $fake->assertReadWith(fn ($s) => str_ends_with($s->path, '.pdf'));
    }
}
