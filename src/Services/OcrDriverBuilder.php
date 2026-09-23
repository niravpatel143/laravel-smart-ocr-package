<?php declare(strict_types=1);

namespace LaravelSmartOCR\Services;

use LaravelSmartOCR\Contracts\OCRDriver;
use LaravelSmartOCR\Drivers\CloudOcrCapable;
use LaravelSmartOCR\Exceptions\OCRException;
use LaravelSmartOCR\Results\OcrResult;

class OcrDriverBuilder
{
    private array $options = [];
    private ?string $fallbackDriver = null;
    private bool $asyncMode = false;

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

    public function async(): static
    {
        $this->asyncMode = true;
        return $this;
    }

    public function fallback(string $driver): static
    {
        $this->fallbackDriver = $driver;
        return $this;
    }

    public function read(mixed $document): OcrResult
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
                $raw = $this->manager->driver($this->fallbackDriver)->extract($document, $this->options);
                return OcrResult::fromLegacy($raw, $this->fallbackDriver);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->fallbackDriver !== null) {
                $raw = $this->manager->driver($this->fallbackDriver)->extract($document, $this->options);
                return OcrResult::fromLegacy($raw, $this->fallbackDriver);
            }
            throw new OCRException($e->getMessage(), (int) $e->getCode(), $e);
        }
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
