<?php declare(strict_types=1);
namespace LaravelSmartOCR\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelSmartOCR\Events\OcrCompleted;
use LaravelSmartOCR\Events\OcrFailed;
use LaravelSmartOCR\Exceptions\AuthenticationException;
use LaravelSmartOCR\Exceptions\ConfigurationException;
use LaravelSmartOCR\Exceptions\RateLimitException;
use LaravelSmartOCR\Exceptions\UnsupportedDocumentException;
use LaravelSmartOCR\Services\OCRManager;

class ProcessOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    /** Exception classes that must not be retried. */
    private array $noRetryExceptions = [
        AuthenticationException::class,
        ConfigurationException::class,
        UnsupportedDocumentException::class,
    ];

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
        } catch (RateLimitException $e) {
            // Release back to queue after the provider's retry-after period
            $this->release($e->retryAfter());
        } catch (\Throwable $e) {
            if ($this->isNoRetryException($e)) {
                // Fail immediately — do not consume remaining retry attempts
                $this->fail($e);
                return;
            }
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        event(new OcrFailed($e, $this->driver, $this->filePath));
    }

    public function backoff(): array
    {
        return [30, 60, 120]; // seconds between attempts 1→2, 2→3
    }

    private function isNoRetryException(\Throwable $e): bool
    {
        foreach ($this->noRetryExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }
        return false;
    }
}
