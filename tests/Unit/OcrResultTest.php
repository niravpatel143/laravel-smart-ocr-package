<?php declare(strict_types=1);

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Tests\TestCase;

class OcrResultTest extends TestCase
{
    public function test_from_array_populates_defaults(): void
    {
        $result = OcrResult::fromArray(['text' => 'Hello', 'provider' => 'google']);
        $this->assertEquals('Hello', $result->text());
        $this->assertEquals('google', $result->provider());
        $this->assertEquals(0.0, $result->confidence());
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals([], $result->pages());
        $this->assertEquals([], $result->tables());
        $this->assertEquals([], $result->fields());
    }

    public function test_from_legacy_wraps_existing_array(): void
    {
        $legacy = ['text' => 'Scanned', 'confidence' => 0.9, 'bounds' => [], 'metadata' => ['engine' => 'tesseract']];
        $result = OcrResult::fromLegacy($legacy, 'tesseract');

        $this->assertEquals('Scanned', $result->text());
        $this->assertEquals(0.9, $result->confidence());
        $this->assertEquals('tesseract', $result->provider());
        $this->assertEquals($legacy, $result->raw());
    }

    public function test_to_array_returns_consistent_schema(): void
    {
        $result = OcrResult::fromArray(['text' => 'Test', 'provider' => 'aws', 'confidence' => 0.95]);
        $arr    = $result->toArray();

        $expected = ['success', 'provider', 'text', 'confidence', 'pages', 'lines', 'words', 'blocks', 'tables', 'fields', 'metadata', 'raw', 'errors'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $arr);
        }
    }

    public function test_all_three_providers_produce_same_schema(): void
    {
        $google = OcrResult::fromArray(['provider' => 'google', 'text' => 'hello'])->toArray();
        $aws    = OcrResult::fromArray(['provider' => 'aws',    'text' => 'hello'])->toArray();
        $azure  = OcrResult::fromArray(['provider' => 'azure',  'text' => 'hello'])->toArray();

        $this->assertSame(array_keys($google), array_keys($aws));
        $this->assertSame(array_keys($google), array_keys($azure));
    }

    public function test_is_not_successful_when_success_false(): void
    {
        $result = OcrResult::fromArray(['success' => false, 'errors' => ['OCR failed']]);
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals(['OCR failed'], $result->errors());
    }
}
