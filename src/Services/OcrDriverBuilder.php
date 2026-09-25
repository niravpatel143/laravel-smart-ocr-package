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
    private ?string $routingMode = null;
    private ?string $escalateDriver = null;
    private float $escalateThreshold = 0.80;
    private int|\DateTimeInterface|\DateInterval|null $cacheTtl = null;
    private mixed $pendingDocument = null;

    public function __construct(
        private readonly OCRDriver $driver,
        private readonly string $driverName,
        private readonly OCRManager $manager,
    ) {}

    /** Store document for fluent chaining: SmartOCR::driver('x')->from($file)->extract($schema) */
    public function from(mixed $document): static
    {
        $this->pendingDocument = $document;
        return $this;
    }

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

    /** Use the cheapest available configured driver automatically. */
    public function cheapest(): self
    {
        $clone = clone $this;
        $clone->routingMode = 'cheapest';
        return $clone;
    }

    /** Use the highest-quality available configured driver automatically. */
    public function best(): self
    {
        $clone = clone $this;
        $clone->routingMode = 'best';
        return $clone;
    }

    /**
     * If primary driver confidence is below $threshold, automatically retry with $driver.
     */
    public function escalateTo(string $driver, float $whenConfidenceBelow = 0.80): self
    {
        $clone = clone $this;
        $clone->escalateDriver = $driver;
        $clone->escalateThreshold = $whenConfidenceBelow;
        return $clone;
    }

    /** Cache results by file hash + driver + options. */
    public function cache(int|\DateTimeInterface|\DateInterval $ttl = 3600): self
    {
        $clone = clone $this;
        $clone->cacheTtl = $ttl;
        return $clone;
    }

    /**
     * Extract structured data from a document using schema extraction.
     * Usage: SmartOCR::driver('tesseract')->from($file)->extract(Invoice::class)
     */
    public function extract(string|array $target, array $options = []): \LaravelSmartOCR\Extraction\ExtractionResult
    {
        if ($this->pendingDocument === null) {
            throw new \LogicException('Call ->from($file) before ->extract(), or use ->read($file) to get an OcrResult and call ExtractionService directly.');
        }
        $ocrResult = $this->read($this->pendingDocument);
        return (new \LaravelSmartOCR\Extraction\ExtractionService())->extract($ocrResult, $target, $options);
    }

    public function read(mixed $document): OcrResult
    {
        $router = new OcrRouter();

        // Routing mode: pick best/cheapest driver
        if ($this->routingMode !== null) {
            $available = array_keys(array_filter(
                config('smart-ocr.drivers', []),
                fn($cfg) => !empty($cfg['api_key'] ?? $cfg['key'] ?? $cfg['binary'] ?? true)
            ));
            $ordered = $this->routingMode === 'cheapest'
                ? $router->cheapestOrder($available)
                : $router->bestOrder($available);
            $driverName = $ordered[0] ?? $this->driverName;
            if ($driverName !== $this->driverName) {
                $builder = $this->manager->driver($driverName);
                // Transfer routing settings to the selected builder
                if ($this->cacheTtl !== null) $builder = $builder->cache($this->cacheTtl);
                if ($this->escalateDriver !== null) $builder = $builder->escalateTo($this->escalateDriver, $this->escalateThreshold);
                return $builder->read($document);
            }
        }

        // Cache check
        $cacheKey = null;
        if ($this->cacheTtl !== null) {
            $fileContent = is_string($document) && file_exists($document) ? file_get_contents($document) : '';
            $cacheKey = hash('sha256', ($fileContent ?: '') . $this->driverName . serialize($this->options));
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($cached !== null) {
                return OcrResult::fromArray($cached);
            }
        }

        $result = $this->runDriver($document);

        // Escalation
        if ($this->escalateDriver !== null) {
            $conf = $result->confidence();
            if ($conf === null || $conf < $this->escalateThreshold) {
                $attempts = [
                    ['driver' => $this->driverName, 'confidence' => $conf],
                ];
                try {
                    $escalatedResult = $this->manager->driver($this->escalateDriver)->read($document);
                    $attempts[] = ['driver' => $this->escalateDriver, 'confidence' => $escalatedResult->confidence()];
                    $data = $escalatedResult->toArray();
                    $data['metadata'] = array_merge($data['metadata'] ?? [], ['attempts' => $attempts]);
                    $result = OcrResult::fromArray($data);
                } catch (\Throwable) {
                    // Escalation failed — keep original result
                }
            }
        }

        // Cache store
        if ($this->cacheTtl !== null && $cacheKey !== null) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result->toArray(), $this->cacheTtl);
        }

        return $result;
    }

    private function runDriver(mixed $document): OcrResult
    {
        try {
            if ($this->driver instanceof CloudOcrCapable) {
                return $this->driver->read($document, $this->options);
            }
            // Legacy driver: wrap extract() result in OcrResult
            $raw = $this->driver->extract($document, $this->options);
            return OcrResult::fromLegacy($raw, $this->driverName);
        } catch (OCRException $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($document, $e);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($document, $e);
            }
            throw new OCRException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function runFallback(mixed $document, \Throwable $original): OcrResult
    {
        // Scrub any potential secret from the reason string (base64-like tokens)
        $reason = preg_replace('/[A-Za-z0-9+\/]{20,}={0,2}/', '[REDACTED]', $original->getMessage());

        try {
            $builder = $this->manager->driver($this->fallbackDriver);
            $result  = $builder->read($document);
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
