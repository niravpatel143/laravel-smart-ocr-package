<?php

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Data\DocumentInput;
use LaravelSmartOCR\Exceptions\InvalidDocumentException;
use LaravelSmartOCR\Services\DocumentValidator;
use LaravelSmartOCR\Tests\TestCase;

class DocumentValidatorTest extends TestCase
{
    private DocumentValidator $validator;
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new DocumentValidator([
            'validation' => [
                'max_file_size'      => 1024 * 1024, // 1 MB
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
                'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            ],
        ]);

        $this->tempFile = tempnam(sys_get_temp_dir(), 'ocr_val_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    public function test_valid_jpeg_passes(): void
    {
        // Write a minimal JPEG header so MIME detection works
        file_put_contents($this->tempFile, "\xFF\xD8\xFF\xE0" . str_repeat('x', 100));
        rename($this->tempFile, $this->tempFile . '.jpg');
        $this->tempFile = $this->tempFile . '.jpg';

        $input = DocumentInput::fromDownloadedUrl(
            'https://example.com/image.jpg',
            $this->tempFile,
            'image.jpg',
            'image/jpeg',
        );

        // Should not throw
        $this->validator->validate($input);
        $this->assertTrue(true);
    }

    public function test_oversized_file_rejected(): void
    {
        file_put_contents($this->tempFile, str_repeat('x', 2 * 1024 * 1024)); // 2 MB

        $input = DocumentInput::fromDownloadedUrl(
            'https://example.com/big.jpg',
            $this->tempFile,
            'big.jpg',
            'image/jpeg',
        );

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('exceeds the maximum');
        $this->validator->validate($input);
    }

    public function test_forbidden_extension_rejected(): void
    {
        file_put_contents($this->tempFile, 'malware');
        $exe = $this->tempFile . '.exe';
        copy($this->tempFile, $exe);

        $input = DocumentInput::fromDownloadedUrl(
            'https://example.com/malware.exe',
            $exe,
            'malware.exe',
            'application/octet-stream',
        );

        try {
            $this->validator->validate($input);
            $this->fail('Expected InvalidDocumentException');
        } catch (InvalidDocumentException $e) {
            $this->assertStringContainsString('exe', $e->getMessage());
        } finally {
            @unlink($exe);
        }
    }

    public function test_mime_type_mismatch_rejected(): void
    {
        // A plain text file renamed to .jpg — extension check passes but MIME fails
        file_put_contents($this->tempFile, 'This is plain text, not a JPEG.');
        $fake = $this->tempFile . '.jpg';
        copy($this->tempFile, $fake);

        $input = DocumentInput::fromDownloadedUrl(
            'https://example.com/fake.jpg',
            $fake,
            'fake.jpg',
            'image/jpeg',
        );

        try {
            $this->validator->validate($input);
            $this->fail('Expected InvalidDocumentException for MIME mismatch');
        } catch (InvalidDocumentException $e) {
            $this->assertStringContainsString('MIME', $e->getMessage());
        } finally {
            @unlink($fake);
        }
    }

    public function test_empty_file_rejected(): void
    {
        // tempnam creates a 0-byte file
        $input = DocumentInput::fromDownloadedUrl(
            'https://example.com/empty.jpg',
            $this->tempFile,
            'empty.jpg',
            'image/jpeg',
        );

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('empty');
        $this->validator->validate($input);
    }
}
