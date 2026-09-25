<?php declare(strict_types=1);

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Tests\TestCase;

class DriverContractTest extends TestCase
{
    /** @dataProvider resultProvider */
    public function test_result_obeys_schema(OcrResult $result): void
    {
        $arr = $result->toArray();

        // Required keys
        foreach (['success', 'provider', 'text', 'confidence', 'pages', 'lines', 'words', 'blocks', 'tables', 'fields', 'metadata', 'raw', 'errors', 'schema_version'] as $key) {
            $this->assertArrayHasKey($key, $arr, "Missing key: $key");
        }

        // Type checks
        $this->assertIsBool($arr['success']);
        $this->assertIsString($arr['text']);
        $this->assertIsArray($arr['pages']);
        $this->assertIsArray($arr['errors']);
        $this->assertEquals(1, $arr['schema_version']);

        // Confidence: null or 0.0-1.0
        if ($arr['confidence'] !== null) {
            $this->assertIsFloat($arr['confidence']);
            $this->assertGreaterThanOrEqual(0.0, $arr['confidence']);
            $this->assertLessThanOrEqual(1.0, $arr['confidence']);
        }
    }

    public static function resultProvider(): array
    {
        return [
            'minimal success'         => [OcrResult::fromArray(['text' => 'hello', 'provider' => 'google'])],
            'with confidence'         => [OcrResult::fromArray(['text' => 'hello', 'provider' => 'aws', 'confidence' => 0.98])],
            'failure result'          => [OcrResult::fromArray(['success' => false, 'errors' => ['failed']])],
            'no confidence providers' => [OcrResult::fromArray(['text' => 'hello', 'provider' => 'tesseract', 'confidence' => 0.9])],
        ];
    }
}
