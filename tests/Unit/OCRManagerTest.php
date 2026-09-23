<?php

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Tests\TestCase;
use LaravelSmartOCR\Services\OCRManager;
use LaravelSmartOCR\Drivers\TesseractDriver;
use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Exceptions\DriverNotAvailableException;
use LaravelSmartOCR\Facades\SmartOCR;
use Mockery;

class OCRManagerTest extends TestCase
{
    protected OCRManager $ocrManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ocrManager = app('smart-ocr');
    }

    public function test_it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(OCRManager::class, $this->ocrManager);
    }

    public function test_default_driver_is_tesseract(): void
    {
        $driver = $this->ocrManager->driver();
        // driver() now returns OcrDriverBuilder wrapping the underlying driver
        $this->assertInstanceOf(\LaravelSmartOCR\Services\OcrDriverBuilder::class, $driver);
    }

    public function test_unknown_driver_throws_driver_not_available_exception(): void
    {
        $this->expectException(DriverNotAvailableException::class);
        $this->expectExceptionMessage('google_vision');

        $this->ocrManager->driver('google_vision');
    }

    public function test_unknown_driver_exception_names_available_drivers(): void
    {
        try {
            $this->ocrManager->driver('aws_textract');
            $this->fail('Expected DriverNotAvailableException');
        } catch (DriverNotAvailableException $e) {
            $this->assertStringContainsString('aws_textract', $e->getMessage());
            $this->assertStringContainsString('tesseract', $e->getMessage());
        }
    }

    public function test_custom_driver_can_be_registered_and_resolved(): void
    {
        $mockDriver = Mockery::mock(OCRDriver::class);
        $mockDriver->shouldReceive('extract')->once()->andReturn($this->mockOCRResponse());

        $this->ocrManager->extend('custom', fn () => $mockDriver);

        $result = $this->ocrManager->driver('custom')->extract('test.jpg');

        $this->assertArrayHasKey('text', $result);
    }

    public function test_custom_driver_factory_must_return_ocr_driver_instance(): void
    {
        $this->ocrManager->extend('bad-driver', fn () => new \stdClass());

        $this->expectException(DriverNotAvailableException::class);
        $this->ocrManager->driver('bad-driver');
    }

    public function test_no_silent_fallback_when_default_is_unknown_driver(): void
    {
        config(['smart-ocr.default' => 'nonexistent_driver_xyz']);

        $this->expectException(DriverNotAvailableException::class);
        $this->ocrManager->driver();
    }

    public function test_extract_delegates_to_driver(): void
    {
        $mock = Mockery::mock(OCRDriver::class);
        $mock->shouldReceive('extract')
            ->once()
            ->andReturn($this->mockOCRResponse());

        $this->ocrManager->extend('tesseract', fn () => $mock);

        $result = $this->ocrManager->driver('tesseract')->extract('test.jpg');

        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('metadata', $result);
    }

    public function test_extract_with_template_applies_template(): void
    {
        $template = $this->createSampleTemplate();

        $mock = Mockery::mock(OCRDriver::class);
        $mock->shouldReceive('extract')->once()->andReturn($this->mockOCRResponse());

        $this->ocrManager->extend('tesseract', fn () => $mock);

        $result = $this->ocrManager->extractWithTemplate('test.jpg', $template->id);

        $this->assertArrayHasKey('template_id', $result);
        $this->assertArrayHasKey('fields', $result);
        $this->assertEquals($template->id, $result['template_id']);
    }

    public function test_extract_table_delegates_to_driver(): void
    {
        $tableData = [
            'table' => [
                ['Item', 'Quantity', 'Price'],
                ['Widget A', '10', '$100.00'],
            ],
            'raw_text' => 'Item    Quantity    Price',
            'metadata' => ['engine' => 'tesseract'],
        ];

        $mock = Mockery::mock(OCRDriver::class);
        $mock->shouldReceive('extractTable')->once()->andReturn($tableData);

        $this->ocrManager->extend('tesseract', fn () => $mock);

        $result = $this->ocrManager->extractTable('test.jpg');

        $this->assertArrayHasKey('table', $result);
        $this->assertCount(2, $result['table']);
    }

    public function test_facade_resolves_to_ocr_manager(): void
    {
        // The facade must resolve to the OCRManager, not a driver
        $this->assertInstanceOf(OCRManager::class, SmartOCR::getFacadeRoot());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}