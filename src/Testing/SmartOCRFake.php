<?php declare(strict_types=1);

namespace LaravelSmartOCR\Testing;

use Closure;
use LaravelSmartOCR\Results\OcrResult;
use PHPUnit\Framework\Assert;

class SmartOCRFake
{
    /** @var array<OcrResult|array> */
    private array $results = [];
    private int $resultIndex = 0;
    private bool $preventStray = false;

    /** @var array<array{source: string, driver: string, options: array}> */
    private array $reads = [];

    public function __construct(array|OcrResult|null $result = null)
    {
        if ($result !== null) {
            if (is_array($result) && !isset($result[0])) {
                // Single result as array
                $this->results[] = OcrResult::fromArray(array_merge(['text' => '', 'provider' => 'fake'], $result));
            } elseif ($result instanceof OcrResult) {
                $this->results[] = $result;
            }
        }
    }

    public function addResult(OcrResult $result): self
    {
        $this->results[] = $result;
        return $this;
    }

    public function sequence(OcrResult ...$results): self
    {
        foreach ($results as $result) {
            $this->results[] = $result;
        }
        return $this;
    }

    public function preventStrayRequests(): self
    {
        $this->preventStray = true;
        return $this;
    }

    public function recordRead(string $source, string $driver, array $options = []): OcrResult
    {
        $this->reads[] = ['source' => $source, 'driver' => $driver, 'options' => $options];

        if (empty($this->results)) {
            if ($this->preventStray) {
                throw new \RuntimeException(
                    "SmartOCR::fake() prevented a real OCR call to driver [{$driver}] for source [{$source}]. " .
                    "Add a result with SmartOCR::fake()->addResult() or SmartOCR::fake(['text' => '...'])."
                );
            }
            return OcrResult::fromArray(['text' => '', 'provider' => $driver, 'success' => true]);
        }

        if ($this->resultIndex < count($this->results)) {
            $result = $this->results[$this->resultIndex];
            $this->resultIndex++;
            return $result instanceof OcrResult ? $result : OcrResult::fromArray($result);
        }

        // Cycle last result
        return end($this->results);
    }

    public function assertRead(string $path = null): void
    {
        Assert::assertNotEmpty($this->reads, 'Expected SmartOCR::read() to be called but it was not.');
        if ($path !== null) {
            $paths = array_column($this->reads, 'source');
            Assert::assertContains($path, $paths, "Expected SmartOCR to read [{$path}] but it was not read.");
        }
    }

    public function assertNothingRead(): void
    {
        Assert::assertEmpty($this->reads, 'Expected SmartOCR::read() not to be called but it was called ' . count($this->reads) . ' time(s).');
    }

    public function assertReadCount(int $count): void
    {
        Assert::assertCount($count, $this->reads, "Expected SmartOCR::read() to be called {$count} time(s) but it was called " . count($this->reads) . ' time(s).');
    }

    public function assertReadWith(Closure $callback): void
    {
        $matched = false;
        foreach ($this->reads as $read) {
            // Create a simple object for the callback
            $source          = new \stdClass();
            $source->path    = $read['source'];
            $source->driver  = $read['driver'];
            $source->options = $read['options'];
            $source->mime    = fn () => 'application/octet-stream';
            if ($callback($source)) {
                $matched = true;
                break;
            }
        }
        Assert::assertTrue($matched, 'SmartOCR::assertReadWith() closure did not match any recorded read.');
    }

    public function assertUsedDriver(string $driver): void
    {
        $drivers = array_column($this->reads, 'driver');
        Assert::assertContains($driver, $drivers, "Expected SmartOCR to use driver [{$driver}] but used: " . implode(', ', $drivers));
    }

    public function getReads(): array
    {
        return $this->reads;
    }
}
