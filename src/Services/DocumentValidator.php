<?php

namespace LaravelSmartOCR\Services;

use LaravelSmartOCR\Data\DocumentInput;
use LaravelSmartOCR\Exceptions\InvalidDocumentException;

class DocumentValidator
{
    private array $allowedExtensions;
    private array $allowedMimeTypes;
    private int $maxFileSize;

    public function __construct(array $config = [])
    {
        $validation = $config['validation'] ?? [];

        $this->allowedExtensions = $validation['allowed_extensions'] ?? [
            'jpg', 'jpeg', 'png', 'pdf', 'tiff', 'bmp',
        ];

        $this->allowedMimeTypes = $validation['allowed_mime_types'] ?? [
            'image/jpeg',
            'image/png',
            'image/tiff',
            'image/bmp',
            'application/pdf',
        ];

        $this->maxFileSize = $validation['max_file_size'] ?? (10 * 1024 * 1024);
    }

    /**
     * Validate a DocumentInput before OCR.
     * Throws InvalidDocumentException describing the exact failure.
     */
    public function validate(DocumentInput $input): void
    {
        $this->assertExists($input);
        $this->assertNotEmpty($input);
        $this->assertSize($input);
        $this->assertExtension($input);
        $this->assertMimeType($input);
    }

    // ── Private checks ────────────────────────────────────────────────────

    private function assertExists(DocumentInput $input): void
    {
        if (! file_exists($input->localPath()) || ! is_readable($input->localPath())) {
            throw InvalidDocumentException::fileNotFound($input->localPath());
        }
    }

    private function assertNotEmpty(DocumentInput $input): void
    {
        if ($input->fileSize() === 0) {
            throw InvalidDocumentException::emptyFile();
        }
    }

    private function assertSize(DocumentInput $input): void
    {
        if ($input->fileSize() > $this->maxFileSize) {
            throw InvalidDocumentException::oversized($input->fileSize(), $this->maxFileSize);
        }
    }

    private function assertExtension(DocumentInput $input): void
    {
        $ext = $input->extension();

        if ($ext === '' || ! in_array($ext, $this->allowedExtensions, true)) {
            throw InvalidDocumentException::forbiddenExtension($ext, $this->allowedExtensions);
        }
    }

    private function assertMimeType(DocumentInput $input): void
    {
        // Re-detect MIME from file content so a renamed file cannot spoof the check.
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($input->localPath()) ?: 'application/octet-stream';

        if (! in_array($detected, $this->allowedMimeTypes, true)) {
            throw InvalidDocumentException::forbiddenMimeType($detected, $this->allowedMimeTypes);
        }
    }
}
