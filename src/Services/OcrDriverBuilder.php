<?php declare(strict_types=1);

namespace LaravelSmartOCR\Services;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Drivers\CloudOcrCapable;
use LaravelSmartOCR\Events\OcrFallbackUsed;
use LaravelSmartOCR\Exceptions\OCRException;
use LaravelSmartOCR\Results\OcrResult;

class OcrDriverBuilder
{
    private array $options = [];
    private ?string $fallbackDriver = null;

    public function __construct(
        private readonly OCRDriver $driver,
        private readonly string $driverName,
        private readonly OCRManager $manager,
    ) {}

    public function language(string $language): static
    {
        $this->options['language'] = $language;
        return $this;
    }

    /**
     * @deprecated async() is a no-op — async processing is handled automatically by each driver.
     *             This method will be removed in v3.0.
     */
    public function async(): static
    {
        trigger_error(
            'OcrDriverBuilder::async() is deprecated and has no effect. Async processing is handled automatically. It will be removed in v3.0.',
            E_USER_DEPRECATED
        );
        return $this;
    }

    public function fallback(string $driver): static
    {
        $this->fallbackDriver = $driver;
        return $this;
    }

    public function read(mixed $document, array $options = []): OcrResult
    {
        $mergedOptions = array_merge($this->options, $options);
        try {
            if ($this->driver instanceof CloudOcrCapable) {
                return $this->driver->read($document, $mergedOptions);
            }
            // Legacy driver: wrap extract() result in OcrResult
            $raw = $this->driver->extract($document, $mergedOptions);
            return OcrResult::fromLegacy($raw, $this->driverName);
        } catch (OCRException $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($document, $e, $mergedOptions);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($document, $e, $mergedOptions);
            }
            throw new OCRException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function runFallback(mixed $document, \Throwable $original, array $options = []): OcrResult
    {
        // Scrub any potential secret from the reason string (base64-like tokens)
        $reason = preg_replace('/[A-Za-z0-9+\/]{20,}={0,2}/', '[REDACTED]', $original->getMessage());

        try {
            $builder = $this->manager->driver($this->fallbackDriver);
            $result  = $builder->read($document, $options);
        } catch (\Throwable $fb) {
            throw new OCRException(
                "Primary driver [{$this->driverName}] and fallback [{$this->fallbackDriver}] both failed: " . $fb->getMessage(),
                0, $original
            );
        }

        $meta        = array_merge($result->metadata(), [
            'fallback_used'    => true,
            'requested_driver' => $this->driverName,
            'actual_driver'    => $this->fallbackDriver,
            'fallback_reason'  => get_class($original) . ': ' . $reason,
        ]);
        $data             = $result->toArray();
        $data['metadata'] = $meta;
        $finalResult      = OcrResult::fromArray($data);

        if (function_exists('event')) {
            event(new OcrFallbackUsed(
                $finalResult,
                $this->driverName,
                $this->fallbackDriver,
                get_class($original) . ': ' . $reason
            ));
        }

        return $finalResult;
    }

    public function extract(mixed $document, array $options = []): array
    {
        return $this->driver->extract($document, array_merge($this->options, $options));
    }

    public function extractText(mixed $document, array $options = []): array
    {
        return $this->driver->extractText($document, array_merge($this->options, $options));
    }

    public function extractTable(mixed $document, array $options = []): array
    {
        return $this->driver->extractTable($document, array_merge($this->options, $options));
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->driver->$method(...$args);
    }
}
