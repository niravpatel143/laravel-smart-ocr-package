<?php declare(strict_types=1);

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Drivers\AzureVisionDriver;
use LaravelSmartOCR\Exceptions\ConfigurationException;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Tests\TestCase;

class AzureVisionDriverTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../fixtures';
    }

    public function test_throws_configuration_exception_without_endpoint(): void
    {
        $this->expectException(ConfigurationException::class);
        new AzureVisionDriver(['key' => 'some-key']); // missing endpoint
    }

    public function test_throws_configuration_exception_without_key(): void
    {
        $this->expectException(ConfigurationException::class);
        new AzureVisionDriver(['endpoint' => 'https://example.cognitiveservices.azure.com']); // missing key
    }

    public function test_get_supported_formats(): void
    {
        $driver = $this->makeDriver();
        $this->assertContains('jpg', $driver->getSupportedFormats());
        $this->assertContains('pdf', $driver->getSupportedFormats());
        $this->assertContains('png', $driver->getSupportedFormats());
    }

    public function test_normalize_response_returns_ocr_result(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/azure_vision_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalize($driver, $raw);

        $this->assertInstanceOf(OcrResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('azure', $result->provider());
        $this->assertStringContainsString('Invoice', $result->text());
        $this->assertGreaterThan(0.0, $result->confidence());
    }

    public function test_lines_have_bounding_boxes(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/azure_vision_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalize($driver, $raw);

        foreach ($result->lines() as $line) {
            $this->assertArrayHasKey('bounding_box', $line);
            $box = $line['bounding_box'];
            $this->assertArrayHasKey('x', $box);
            $this->assertArrayHasKey('y', $box);
            $this->assertArrayHasKey('width', $box);
            $this->assertArrayHasKey('height', $box);
            $this->assertArrayHasKey('points', $box);
        }
    }

    public function test_metadata_has_engine(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/azure_vision_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalize($driver, $raw);

        $this->assertArrayHasKey('engine', $result->metadata());
        $this->assertEquals('azure-vision', $result->metadata()['engine']);
    }

    public function test_consistent_schema_across_providers(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/azure_vision_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalize($driver, $raw);
        $arr    = $result->toArray();

        foreach (['success', 'provider', 'text', 'confidence', 'pages', 'lines', 'words', 'blocks', 'tables', 'fields', 'metadata', 'raw'] as $key) {
            $this->assertArrayHasKey($key, $arr);
        }
    }

    private function makeDriver(): AzureVisionDriver
    {
        return new AzureVisionDriver([
            'endpoint' => 'https://example.cognitiveservices.azure.com',
            'key'      => 'fake-key',
        ]);
    }

    private function callNormalize(AzureVisionDriver $driver, array $raw): OcrResult
    {
        $ref = new \ReflectionMethod($driver, 'normalizeResponse');
        $ref->setAccessible(true);
        return $ref->invoke($driver, $raw, []);
    }
}
