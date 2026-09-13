<?php

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Data\DocumentInput;
use LaravelSmartOCR\Tests\TestCase;

class DocumentInputTest extends TestCase
{
    private string $sampleFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sampleFile = tempnam(sys_get_temp_dir(), 'ocr_test_');
        file_put_contents($this->sampleFile, 'dummy content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->sampleFile)) {
            unlink($this->sampleFile);
        }
        parent::tearDown();
    }

    public function test_from_path_sets_source_type(): void
    {
        $input = DocumentInput::fromPath($this->sampleFile);
        $this->assertSame(DocumentInput::SOURCE_PATH, $input->sourceType());
    }

    public function test_from_path_retains_filename(): void
    {
        $input = DocumentInput::fromPath($this->sampleFile);
        $this->assertSame(basename($this->sampleFile), $input->originalFilename());
    }

    public function test_from_path_sets_local_path(): void
    {
        $input = DocumentInput::fromPath($this->sampleFile);
        $this->assertSame($this->sampleFile, $input->localPath());
    }

    public function test_from_path_detects_file_size(): void
    {
        $input = DocumentInput::fromPath($this->sampleFile);
        $this->assertSame(filesize($this->sampleFile), $input->fileSize());
    }

    public function test_from_path_throws_for_missing_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentInput::fromPath('/does/not/exist/file.jpg');
    }

    public function test_from_downloaded_url_owns_file(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'ocr_url_');
        file_put_contents($temp, 'remote content');

        $input = DocumentInput::fromDownloadedUrl(
            remoteUrl: 'https://example.com/doc.jpg',
            localTempPath: $temp,
            originalFilename: 'doc.jpg',
            mimeType: 'image/jpeg',
        );

        $this->assertTrue($input->isRemote());
        $this->assertSame('https://example.com/doc.jpg', $input->remoteUrl());

        // cleanup() should delete the temp file
        $input->cleanup();
        $this->assertFileDoesNotExist($temp);
    }

    public function test_from_path_does_not_delete_file_on_cleanup(): void
    {
        $input = DocumentInput::fromPath($this->sampleFile);
        $input->cleanup();

        // fromPath does not own the file
        $this->assertFileExists($this->sampleFile);
    }

    public function test_is_pdf_detection(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'ocr_test_') . '.pdf';
        file_put_contents($pdf, '%PDF-1.4');

        $input = DocumentInput::fromDownloadedUrl(
            'https://example.com/doc.pdf',
            $pdf,
            'doc.pdf',
            'application/pdf',
        );

        $this->assertTrue($input->isPdf());
        $this->assertFalse($input->isImage());

        @unlink($pdf);
    }
}
