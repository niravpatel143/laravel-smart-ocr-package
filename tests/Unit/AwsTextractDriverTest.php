<?php declare(strict_types=1);

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Drivers\AwsTextractDriver;
use LaravelSmartOCR\Exceptions\ConfigurationException;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Tests\TestCase;

class AwsTextractDriverTest extends TestCase
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
        new AwsTextractDriver([]);
    }

    public function test_get_supported_formats(): void
    {
        $driver = $this->makeDriver();
        $this->assertContains('pdf', $driver->getSupportedFormats());
        $this->assertContains('jpg', $driver->getSupportedFormats());
    }

    public function test_normalize_blocks_returns_ocr_result(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/aws_textract_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalizeBlocks($driver, $raw['Blocks']);

        $this->assertInstanceOf(OcrResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('aws', $result->provider());
        $this->assertStringContainsString('Invoice', $result->text());
        $this->assertGreaterThan(0.0, $result->confidence());
    }

    public function test_tables_are_normalized(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/aws_textract_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalizeBlocks($driver, $raw['Blocks']);

        $tables = $result->tables();
        $this->assertNotEmpty($tables);
        $this->assertArrayHasKey('headers', $tables[0]);
        $this->assertArrayHasKey('rows', $tables[0]);
    }

    public function test_lines_have_confidence(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/aws_textract_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalizeBlocks($driver, $raw['Blocks']);

        foreach ($result->lines() as $line) {
            $this->assertArrayHasKey('confidence', $line);
            $this->assertGreaterThanOrEqual(0.0, $line['confidence']);
            $this->assertLessThanOrEqual(1.0, $line['confidence']);
        }
    }

    public function test_to_array_schema_matches_all_providers(): void
    {
        $raw    = json_decode(file_get_contents($this->fixtureDir . '/aws_textract_response.json'), true);
        $driver = $this->makeDriver();
        $result = $this->callNormalizeBlocks($driver, $raw['Blocks']);
        $arr    = $result->toArray();

        foreach (['success', 'provider', 'text', 'confidence', 'pages', 'lines', 'words', 'blocks', 'tables', 'fields', 'metadata', 'raw'] as $key) {
            $this->assertArrayHasKey($key, $arr, "Missing key: {$key}");
        }
    }

    private function makeDriver(): AwsTextractDriver
    {
        return new AwsTextractDriver(['key' => 'fake-key', 'secret' => 'fake-secret']);
    }

    private function callNormalizeBlocks(AwsTextractDriver $driver, array $blocks): OcrResult
    {
        $ref = new \ReflectionMethod($driver, 'normalizeBlocks');
        $ref->setAccessible(true);
        return $ref->invoke($driver, $blocks, 'aws', []);
    }
}
