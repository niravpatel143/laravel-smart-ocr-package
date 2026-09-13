<?php

namespace LaravelSmartOCR\Data;

use Illuminate\Http\UploadedFile;

/**
 * Immutable value object representing a document to be OCR-processed.
 *
 * Factories normalise every input type (local path, UploadedFile, remote URL)
 * into a single representation so downstream code never needs to branch on
 * input type.
 */
final class DocumentInput
{
    public const SOURCE_PATH = 'path';
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_URL = 'url';

    private function __construct(
        private readonly string $sourceType,
        private readonly string $localPath,
        private readonly string $originalFilename,
        private readonly string $mimeType,
        private readonly string $extension,
        private readonly int $fileSize,
        private readonly ?string $remoteUrl,
        private readonly bool $ownsFile,   // whether this object should delete localPath on cleanup
    ) {}

    // ── Factories ──────────────────────────────────────────────────────────

    public static function fromPath(string $path): self
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File does not exist: {$path}");
        }

        return new self(
            sourceType: self::SOURCE_PATH,
            localPath: $path,
            originalFilename: basename($path),
            mimeType: self::detectMime($path),
            extension: strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            fileSize: filesize($path),
            remoteUrl: null,
            ownsFile: false,
        );
    }

    public static function fromUploadedFile(UploadedFile $file): self
    {
        return new self(
            sourceType: self::SOURCE_UPLOAD,
            localPath: $file->getRealPath(),
            originalFilename: $file->getClientOriginalName(),
            mimeType: $file->getMimeType() ?? $file->getClientMimeType(),
            extension: strtolower($file->getClientOriginalExtension()),
            fileSize: $file->getSize(),
            remoteUrl: null,
            ownsFile: false,
        );
    }

    /**
     * Create from a downloaded remote URL.
     * The caller provides the already-downloaded local temp path.
     * ownsFile=true so the temp file is deleted on cleanup().
     */
    public static function fromDownloadedUrl(
        string $remoteUrl,
        string $localTempPath,
        string $originalFilename,
        string $mimeType,
    ): self {
        return new self(
            sourceType: self::SOURCE_URL,
            localPath: $localTempPath,
            originalFilename: $originalFilename,
            mimeType: $mimeType,
            extension: strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)),
            fileSize: filesize($localTempPath),
            remoteUrl: $remoteUrl,
            ownsFile: true,
        );
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    public function sourceType(): string { return $this->sourceType; }
    public function localPath(): string { return $this->localPath; }
    public function originalFilename(): string { return $this->originalFilename; }
    public function mimeType(): string { return $this->mimeType; }
    public function extension(): string { return $this->extension; }
    public function fileSize(): int { return $this->fileSize; }
    public function remoteUrl(): ?string { return $this->remoteUrl; }

    public function isPdf(): bool
    {
        return $this->extension === 'pdf' || $this->mimeType === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isRemote(): bool
    {
        return $this->sourceType === self::SOURCE_URL;
    }

    /**
     * Delete the local temp file if this object owns it.
     * Safe to call multiple times.
     */
    public function cleanup(): void
    {
        if ($this->ownsFile && file_exists($this->localPath)) {
            @unlink($this->localPath);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($path) ?: 'application/octet-stream';
    }
}
