<?php declare(strict_types=1);
namespace LaravelSmartOCR\Tests\Unit;
use LaravelSmartOCR\Output\Chunker;
use LaravelSmartOCR\Output\MarkdownRenderer;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Tests\TestCase;

class MarkdownOutputTest extends TestCase
{
    public function test_plain_text_passes_through(): void
    {
        $result = OcrResult::fromArray(['text' => 'Hello world', 'provider' => 'pdf']);
        $this->assertEquals('Hello world', $result->toMarkdown());
    }

    public function test_table_renders_as_markdown(): void
    {
        $result = OcrResult::fromArray([
            'text'     => '',
            'provider' => 'aws',
            'tables'   => [[
                'headers' => ['Item', 'Price'],
                'rows'    => [['Item' => 'Widget', 'Price' => '$10'], ['Item' => 'Gadget', 'Price' => '$20']],
            ]],
        ]);
        $md = $result->toMarkdown();
        $this->assertStringContainsString('| Item |', $md);
        $this->assertStringContainsString('| Widget |', $md);
    }

    public function test_chunks_splits_long_text(): void
    {
        $long = implode("\n\n", array_fill(0, 20, str_repeat('word ', 100)));
        $result = OcrResult::fromArray(['text' => $long, 'provider' => 'pdf']);
        $chunks = $result->chunks(maxTokens: 200);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(300, $chunk->tokenEstimate); // allow slight overage
        }
    }

    public function test_chunks_preserve_heading(): void
    {
        $md = "## Invoice\n\nThis is an invoice.\n\n## Summary\n\nTotal: \$100";
        $chunker = new Chunker();
        $chunks = $chunker->chunk($md, maxTokens: 500);
        $headings = array_column(array_map(fn($c) => $c->toArray(), $chunks), 'heading');
        $this->assertContains('## Invoice', $headings);
        $this->assertContains('## Summary', $headings);
    }
}
