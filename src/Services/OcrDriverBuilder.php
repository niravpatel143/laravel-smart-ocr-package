<?php declare(strict_types=1);

namespace LaravelSmartOCR\Services;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Data\DocumentSource;
use LaravelSmartOCR\Drivers\CloudOcrCapable;
use LaravelSmartOCR\Events\OcrFallbackUsed;
use LaravelSmartOCR\Exceptions\OCRException;
use LaravelSmartOCR\Results\OcrResult;

class OcrDriverBuilder
{
    private array $options = [];
    private ?string $fallbackDriver = null;
    private ?DocumentSource $source = null;
    private ?string $routingMode = null;
    private ?string $escalateDriver = null;
    private float $escalateThreshold = 0.80;
    private int|\DateTimeInterface|\DateInterval|null $cacheTtl = null;
    private mixed $pendingDocument = null;

    private const PAGE_RANGE_CAPABLE_DRIVERS = ['aws', 'azure', 'google', 'pdf'];

    public function __construct(
        private readonly OCRDriver $driver,
        private readonly string $driverName,
        private readonly OCRManager $manager,
    ) {}

    // ── Source / fluent input ─────────────────────────────────────────────

    public function from(mixed $document): static
    {
        $this->pendingDocument = $document;
        return $this;
    }

    public function withSource(DocumentSource $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function pages(string $range): static
    {
        $this->options['pages'] = $range;
        return $this;
    }

    public function language(string $language): static
    {
        $this->options['language'] = $language;
        return $this;
    }

    public function fallback(string $driver): static
    {
        $this->fallbackDriver = $driver;
        return $this;
    }

    public function using(string $driver): static
    {
        return $this->manager->driver($driver)
            ->withOptions($this->options)
            ->withFallback($this->fallbackDriver)
            ->withSource($this->source ?? DocumentSource::parse($this->pendingDocument ?? ''));
    }

    public function driver(string $driver): static
    {
        return $this->using($driver);
    }

    public function withOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    public function withFallback(?string $driver): static
    {
        $this->fallbackDriver = $driver;
        return $this;
    }

    // ── Routing helpers ───────────────────────────────────────────────────

    public function cheapest(): self
    {
        $clone = clone $this;
        $clone->routingMode = 'cheapest';
        return $clone;
    }

    public function best(): self
    {
        $clone = clone $this;
        $clone->routingMode = 'best';
        return $clone;
    }

    public function escalateTo(string $driver, float $whenConfidenceBelow = 0.80): self
    {
        $clone = clone $this;
        $clone->escalateDriver = $driver;
        $clone->escalateThreshold = $whenConfidenceBelow;
        return $clone;
    }

    public function cache(int|\DateTimeInterface|\DateInterval $ttl = 3600): self
    {
        $clone = clone $this;
        $clone->cacheTtl = $ttl;
        return $clone;
    }

    // ── Async (deprecated no-op) ──────────────────────────────────────────

    public function async(): static
    {
        trigger_error('OcrDriverBuilder::async() is deprecated and has no effect. Remove it from your code.', E_USER_DEPRECATED);
        return $this;
    }

    // ── Main read() ───────────────────────────────────────────────────────

    public function read(mixed $document = null, array $options = []): OcrResult
    {
        $resolved = $document ?? ($this->source?->path()) ?? $this->pendingDocument;
        if ($resolved === null) {
            throw new OCRException('No document source provided. Pass a path to read() or use SmartOCR::from().');
        }

        $mergedOptions = array_merge($this->options, $options);

        // Warn if page range unsupported
        if (isset($mergedOptions['pages']) && !in_array($this->driverName, self::PAGE_RANGE_CAPABLE_DRIVERS, true)) {
            $mergedOptions['_page_range_warning'] = "Driver [{$this->driverName}] does not support page ranges; 'pages' option was ignored.";
        }

        // Routing mode: pick best/cheapest driver automatically
        if ($this->routingMode !== null) {
            $router    = new OcrRouter();
            $available = array_keys(array_filter(
                config('smart-ocr.drivers', []),
                fn($cfg) => !empty($cfg['api_key'] ?? $cfg['key'] ?? $cfg['binary'] ?? true)
            ));
            $ordered    = $this->routingMode === 'cheapest' ? $router->cheapestOrder($available) : $router->bestOrder($available);
            $driverName = $ordered[0] ?? $this->driverName;
            if ($driverName !== $this->driverName) {
                $builder = $this->manager->driver($driverName);
                if ($this->cacheTtl !== null) $builder = $builder->cache($this->cacheTtl);
                if ($this->escalateDriver !== null) $builder = $builder->escalateTo($this->escalateDriver, $this->escalateThreshold);
                return $builder->read($resolved, $mergedOptions);
            }
        }

        // Cache check
        $cacheKey = null;
        if ($this->cacheTtl !== null) {
            $content  = is_string($resolved) && file_exists($resolved) ? (string) file_get_contents($resolved) : '';
            $cacheKey = hash('sha256', $content . $this->driverName . serialize($mergedOptions));
            $cached   = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($cached !== null) {
                return OcrResult::fromArray($cached);
            }
        }

        $result = $this->runDriver($resolved, $mergedOptions);

        // Escalation
        if ($this->escalateDriver !== null) {
            $conf = $result->confidence();
            if ($conf === null || $conf < $this->escalateThreshold) {
                $attempts = [['driver' => $this->driverName, 'confidence' => $conf]];
                try {
                    $escalated  = $this->manager->driver($this->escalateDriver)->read($resolved, $mergedOptions);
                    $attempts[] = ['driver' => $this->escalateDriver, 'confidence' => $escalated->confidence()];
                    $data       = $escalated->toArray();
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

    private function runDriver(mixed $document, array $options = []): OcrResult
    {
        try {
            if ($this->driver instanceof CloudOcrCapable) {
                $result = $this->driver->read($document, $options);
            } else {
                $raw    = $this->driver->extract($document, $options);
                $result = OcrResult::fromLegacy($raw, $this->driverName);
            }
        } catch (OCRException $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($document, $e, $options);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($document, $e, $options);
            }
            throw new OCRException($e->getMessage(), (int) $e->getCode(), $e);
        }

        if (isset($options['_page_range_warning'])) {
            $data                     = $result->toArray();
            $data['metadata']['warnings'][] = $options['_page_range_warning'];
            $result = OcrResult::fromArray($data);
        }

        return $result;
    }

    // ── Schema extraction ─────────────────────────────────────────────────

    public function extract(string|array $target, array $options = []): \LaravelSmartOCR\Extraction\ExtractionResult
    {
        $ocrResult = $this->read($this->pendingDocument ?? null);
        return (new \LaravelSmartOCR\Extraction\ExtractionService())->extract($ocrResult, $target, $options);
    }

    // ── Fallback ──────────────────────────────────────────────────────────

    private function runFallback(mixed $document, \Throwable $original, array $options = []): OcrResult
    {
        $reason = preg_replace('/[A-Za-z0-9+\/]{20,}={0,2}/', '[REDACTED]', $original->getMessage());

        try {
            $result = $this->manager->driver($this->fallbackDriver)->read($document, $options);
        } catch (\Throwable $fb) {
            throw new OCRException(
                "Primary driver [{$this->driverName}] and fallback [{$this->fallbackDriver}] both failed: " . $fb->getMessage(),
                0, $original
            );
        }

        $meta       = array_merge($result->metadata(), [
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

    // ── Pass-throughs ─────────────────────────────────────────────────────

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
