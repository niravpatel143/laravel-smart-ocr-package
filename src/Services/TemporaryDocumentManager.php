<?php

namespace LaravelSmartOCR\Services;

use LaravelSmartOCR\Exceptions\InvalidDocumentException;

/**
 * Creates and tracks temporary files used during OCR processing.
 *
 * All temp files use random names — no user-controlled input ever
 * reaches the filesystem path.  cleanup() is safe to call on both
 * success and failure paths.
 */
class TemporaryDocumentManager
{
    private string $tempDir;

    /** @var string[] Paths registered for deletion */
    private array $tracked = [];

    public function __construct(?string $tempDir = null)
    {
        $this->tempDir = rtrim($tempDir ?? sys_get_temp_dir(), '/\\');
    }

    /**
     * Create a new empty temporary file with a random name.
     *
     * @param string $extension e.g. 'jpg' — only [a-z0-9] accepted
     */
    public function create(string $extension = 'tmp'): string
    {
        // Sanitise extension: strip everything that isn't a safe alphanumeric
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        if ($extension === '') {
            $extension = 'tmp';
        }

        $path = $this->tempDir . '/ocr_' . bin2hex(random_bytes(16)) . '.' . $extension;
        touch($path);
        $this->tracked[] = $path;

        return $path;
    }

    /**
     * Register an existing file for cleanup (e.g. uploaded temp files).
     */
    public function track(string $path): void
    {
        $this->tracked[] = $path;
    }

    /**
     * Delete all tracked temporary files.
     * Safe to call multiple times — silently skips missing files.
     */
    public function cleanup(): void
    {
        foreach ($this->tracked as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tracked = [];
    }

    /**
     * Return the list of currently tracked paths (for testing).
     *
     * @return string[]
     */
    public function tracked(): array
    {
        return $this->tracked;
    }

    /**
     * Ensure the given path resolves inside the allowed temp directory.
     * Call this when a path comes from external input before using it.
     */
    public function assertSafePath(string $path): void
    {
        $real = realpath(dirname($path));

        if ($real === false || ! str_starts_with($real, realpath($this->tempDir))) {
            throw new InvalidDocumentException(
                "Temporary file path is outside the allowed directory."
            );
        }
    }
}
