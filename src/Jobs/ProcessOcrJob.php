<?php declare(strict_types=1);

namespace LaravelSmartOCR\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelSmartOCR\Events\OcrCompleted;
use LaravelSmartOCR\Events\OcrFailed;
use LaravelSmartOCR\Services\OCRManager;

class ProcessOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        public readonly string $filePath,
        public readonly string $driver,
        public readonly array $options = [],
    ) {}

    public function handle(OCRManager $manager): void
    {
        try {
            $result = $manager->driver($this->driver)->read($this->filePath, $this->options);
            event(new OcrCompleted($result, $this->driver));
        } catch (\Throwable $e) {
            event(new OcrFailed($e, $this->driver, $this->filePath));
            throw $e;
        }
    }
}
