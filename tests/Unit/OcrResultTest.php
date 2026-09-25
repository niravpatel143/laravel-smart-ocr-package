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
        $this->assertNull($result->confidence()); // no confidence value supplied
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals([], $result->pages());
        $this->assertEquals([], $result->tables());
        $this->assertEquals([], $result->fields());
    }

    public function test_from_array_with_real_confidence(): void
    {
        $result = OcrResult::fromArray(['text' => 'Hello', 'provider' => 'google', 'confidence' => 0.97]);
        $this->assertEquals(0.97, $result->confidence());
    }

    public function test_from_legacy_tesseract_returns_null_confidence(): void
    {
        $legacy = ['text' => 'Scanned', 'confidence' => 0.9, 'bounds' => [], 'metadata' => ['engine' => 'tesseract']];
        $result = OcrResult::fromLegacy($legacy, 'tesseract');

        $this->assertEquals('Scanned', $result->text());
        $this->assertNull($result->confidence()); // Tesseract does not expose a real confidence score
        $this->assertEquals('tesseract', $result->provider());
        $this->assertEquals($legacy, $result->raw());
    }

    public function test_from_legacy_claude_returns_null_confidence(): void
    {
        $legacy = ['text' => 'Hello', 'confidence' => 0.95, 'bounds' => [], 'metadata' => []];
        $result = OcrResult::fromLegacy($legacy, 'claude');
        $this->assertNull($result->confidence()); // 0.95 was a static placeholder, not a real score
    }

    public function test_from_legacy_openai_returns_null_confidence(): void
    {
        $legacy = ['text' => 'Hello', 'confidence' => 0.95, 'bounds' => [], 'metadata' => []];
        $result = OcrResult::fromLegacy($legacy, 'openai');
        $this->assertNull($result->confidence());
    }

    public function test_cloud_provider_confidence_is_returned(): void
    {
        $result = OcrResult::fromArray(['provider' => 'aws', 'confidence' => 0.98]);
        $this->assertEquals(0.98, $result->confidence());

        $result = OcrResult::fromArray(['provider' => 'google', 'confidence' => 0.96]);
        $this->assertEquals(0.96, $result->confidence());

        $result = OcrResult::fromArray(['provider' => 'azure', 'confidence' => 0.99]);
        $this->assertEquals(0.99, $result->confidence());
    }

    public function test_to_array_returns_consistent_schema(): void
    {
        $result = OcrResult::fromArray(['text' => 'Test', 'provider' => 'aws', 'confidence' => 0.95]);
        $arr    = $result->toArray();

        $expected = ['success', 'provider', 'text', 'confidence', 'pages', 'lines', 'words', 'blocks', 'tables', 'fields', 'metadata', 'raw', 'errors', 'schema_version'];
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
