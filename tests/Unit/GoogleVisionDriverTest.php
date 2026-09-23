<?php declare(strict_types=1);

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Drivers\GoogleVisionDriver;
use LaravelSmartOCR\Exceptions\ConfigurationException;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Tests\TestCase;

class GoogleVisionDriverTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../fixtures';
    }

    public function test_throws_configuration_exception_when_no_credentials(): void
    {
        $this->expectException(ConfigurationException::class);
        new GoogleVisionDriver([]);
    }

    public function test_get_supported_formats(): void
    {
        $driver = $this->makeDriver();
        $this->assertContains('jpg', $driver->getSupportedFormats());
        $this->assertContains('pdf', $driver->getSupportedFormats());
        $this->assertContains('png', $driver->getSupportedFormats());
    }

    public function test_get_supported_languages(): void
    {
        $driver = $this->makeDriver();
        $this->assertArrayHasKey('auto', $driver->getSupportedLanguages());
    }

    public function test_normalize_response_returns_ocr_result(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/google_vision_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalizeImage($driver, $raw);

        $this->assertInstanceOf(OcrResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('google', $result->provider());
        $this->assertStringContainsString('Invoice', $result->text());
        $this->assertGreaterThan(0.0, $result->confidence());
        $this->assertIsArray($result->pages());
        $this->assertIsArray($result->lines());
        $this->assertIsArray($result->words());
        $this->assertIsArray($result->blocks());
        $this->assertIsArray($result->tables());
        $this->assertIsArray($result->raw());
    }

    public function test_to_array_has_expected_keys(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/google_vision_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalizeImage($driver, $raw);
        $arr    = $result->toArray();

        $this->assertArrayHasKey('success', $arr);
        $this->assertArrayHasKey('provider', $arr);
        $this->assertArrayHasKey('text', $arr);
        $this->assertArrayHasKey('confidence', $arr);
        $this->assertArrayHasKey('pages', $arr);
        $this->assertArrayHasKey('lines', $arr);
        $this->assertArrayHasKey('words', $arr);
        $this->assertArrayHasKey('blocks', $arr);
        $this->assertArrayHasKey('tables', $arr);
        $this->assertArrayHasKey('fields', $arr);
        $this->assertArrayHasKey('metadata', $arr);
        $this->assertArrayHasKey('raw', $arr);
    }

    public function test_words_have_bounding_boxes(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/google_vision_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalizeImage($driver, $raw);

        foreach ($result->words() as $word) {
            $this->assertArrayHasKey('text', $word);
            $this->assertArrayHasKey('confidence', $word);
            $this->assertArrayHasKey('bounding_box', $word);
            $this->assertArrayHasKey('x', $word['bounding_box']);
            $this->assertArrayHasKey('y', $word['bounding_box']);
            $this->assertArrayHasKey('width', $word['bounding_box']);
            $this->assertArrayHasKey('height', $word['bounding_box']);
        }
    }

    private function makeDriver(): GoogleVisionDriver
    {
        return new GoogleVisionDriver(['api_key' => 'test-key-not-real']);
    }

    private function callNormalizeImage(GoogleVisionDriver $driver, array $raw): OcrResult
    {
        $ref = new \ReflectionMethod($driver, 'normalizeImageResponse');
        $ref->setAccessible(true);
        return $ref->invoke($driver, $raw, []);
    }
}
