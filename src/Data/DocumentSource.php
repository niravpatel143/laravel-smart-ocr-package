<?php declare(strict_types=1);

namespace LaravelSmartOCR\Data;

use Illuminate\Http\UploadedFile;

class DocumentSource
{
    private string $path;
    private ?string $mime = null;
    private ?int $size = null;
    private string $origin; // 'path', 'upload', 'disk', 'bytes'

    private function __construct(string $path, string $origin)
    {
        $this->path   = $path;
        $this->origin = $origin;
    }

    public static function fromPath(string $path): self
    {
        $source = new self($path, 'path');
        return $source;
    }

    public static function fromUpload(UploadedFile $file): self
    {
        $source       = new self($file->getRealPath() ?: $file->getPathname(), 'upload');
        $source->mime = $file->getMimeType();
        return $source;
    }

    public static function fromDisk(string $disk, string $path): self
    {
        // Copy to a temp file
        $tempPath = tempnam(sys_get_temp_dir(), 'socr_');
        file_put_contents($tempPath, \Illuminate\Support\Facades\Storage::disk($disk)->get($path));
        $source = new self($tempPath, 'disk');
        return $source;
    }

    public static function fromBytes(string $bytes, string $filename = 'document'): self
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'socr_');
        file_put_contents($tempPath, $bytes);
        $source = new self($tempPath, 'bytes');
        return $source;
    }

    /** Parse "s3:invoices/a.pdf" or "local:uploads/doc.pdf" disk paths */
    public static function parse(mixed $input): self
    {
        if ($input instanceof self) {
            return $input;
        }
        if ($input instanceof UploadedFile) {
            return self::fromUpload($input);
        }
        if (is_string($input) && preg_match('/^([a-z0-9_\-]+):(.+)$/i', $input, $m)) {
            return self::fromDisk($m[1], $m[2]);
        }
        if (is_string($input)) {
            return self::fromPath($input);
        }
        throw new \InvalidArgumentException('Unsupported DocumentSource input type: ' . gettype($input));
    }

    public function path(): string   { return $this->path; }
    public function origin(): string { return $this->origin; }

    public function mime(): string
    {
        if ($this->mime === null) {
            $finfo      = new \finfo(FILEINFO_MIME_TYPE);
            $this->mime = $finfo->file($this->path) ?: 'application/octet-stream';
        }
        return $this->mime;
    }

    public function size(): int
    {
        if ($this->size === null) {
            $this->size = (int) filesize($this->path);
        }
        return $this->size;
    }

    public function hash(): string
    {
        return hash_file('sha256', $this->path);
    }

    public function pageCount(): int
    {
        if (!str_contains($this->mime(), 'pdf')) {
            return 1;
        }
        $content = file_get_contents($this->path);
        if ($content === false) return 1;
        return max(1, substr_count($content, '/Type /Page') ?: substr_count($content, '/Type/Page'));
    }

    public function isTemporary(): bool
    {
        return in_array($this->origin, ['disk', 'bytes'], true);
    }

    public function cleanup(): void
    {
        if ($this->isTemporary() && file_exists($this->path)) {
            @unlink($this->path);
        }
    }
}
