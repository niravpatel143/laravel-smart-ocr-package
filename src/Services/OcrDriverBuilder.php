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

    /**
     * Drivers that support page-range filtering natively.
     * Others receive a warning in result metadata.
     */
    private const PAGE_RANGE_CAPABLE_DRIVERS = ['aws', 'azure', 'google', 'pdf'];

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

    /**
     * Store a pre-resolved DocumentSource (set by OCRManager::from()).
     */
    public function withSource(DocumentSource $source): static
    {
        $this->source = $source;
        return $this;
    }

    /**
     * Set a page range (e.g. '1-3', '2') to pass to the driver.
     * Drivers that do not support page ranges will emit a warning in result metadata.
     */
    public function pages(string $range): static
    {
        $this->options['pages'] = $range;
        return $this;
    }

    /**
     * Also usable as a driver alias (to match the from() style).
     */
    public function using(string $driver): static
    {
        // Rebuild with a different driver via the manager
        return $this->manager->driver($driver)
            ->withOptions($this->options)
            ->withFallback($this->fallbackDriver)
            ->withSource($this->source);
    }

    /**
     * Fluent alias for driver() when chained from from().
     */
    public function driver(string $driver): static
    {
        return $this->using($driver);
    }

    /**
     * Internal: merge an options array (used by using()/driver()).
     */
    public function withOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Internal: carry over fallback (used by using()/driver()).
     */
    public function withFallback(?string $driver): static
    {
        $this->fallbackDriver = $driver;
        return $this;
    }

    /**
     * Run OCR on the given document (or the stored source).
     *
     * @param mixed|null $document File path, UploadedFile, or DocumentSource.
     *                             If null and withSource() was called, the stored source is used.
     */
    public function read(mixed $document = null, array $options = []): OcrResult
    {
        // Resolve the actual document to process
        $resolvedDocument = $document ?? ($this->source?->path());
        if ($resolvedDocument === null) {
            throw new OCRException('No document source provided. Pass a path to read() or use SmartOCR::from().');
        }

        $mergedOptions = array_merge($this->options, $options);

        // Warn if page range was requested but driver doesn't natively support it
        if (isset($mergedOptions['pages']) && !in_array($this->driverName, self::PAGE_RANGE_CAPABLE_DRIVERS, true)) {
            $mergedOptions['_page_range_warning'] = "Driver [{$this->driverName}] does not support page ranges; 'pages' option was ignored.";
        }

        try {
            if ($this->driver instanceof CloudOcrCapable) {
                $result = $this->driver->read($resolvedDocument, $mergedOptions);
            } else {
                // Legacy driver: wrap extract() result in OcrResult
                $raw    = $this->driver->extract($resolvedDocument, $mergedOptions);
                $result = OcrResult::fromLegacy($raw, $this->driverName);
            }
        } catch (OCRException $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($resolvedDocument, $e, $mergedOptions);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->fallbackDriver !== null) {
                return $this->runFallback($resolvedDocument, $e, $mergedOptions);
            }
            throw new OCRException($e->getMessage(), (int) $e->getCode(), $e);
        }

        // Attach page-range warning to metadata if applicable
        if (isset($mergedOptions['_page_range_warning'])) {
            $data             = $result->toArray();
            $data['metadata']['warnings'][] = $mergedOptions['_page_range_warning'];
            $result = OcrResult::fromArray($data);
        }

        return $result;
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
