<?php declare(strict_types=1);
namespace LaravelSmartOCR\Testing;

use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Services\OCRManager;

class SmartOCRFake
{
    private FakeOcrDriver $fakeDriver;
    private array $queuedPaths = [];

    public function __construct(private readonly OCRManager $manager)
    {
        $this->fakeDriver = new FakeOcrDriver();
        $manager->extend('fake', fn () => $this->fakeDriver);
    }

    public function addResult(OcrResult $result): void
    {
        $this->fakeDriver->addResult($result);
    }

    public function assertRead(string $path): void
    {
        $this->fakeDriver->assertRead($path);
    }

    public function assertNothingRead(): void
    {
        $this->fakeDriver->assertNothingRead();
    }

    public function assertQueued(string $path): void
    {
        \PHPUnit\Framework\Assert::assertContains(
            $path, $this->queuedPaths,
            "Expected [{$path}] to have been queued but it was not."
        );
    }

    public function recordQueued(string $path): void
    {
        $this->queuedPaths[] = $path;
    }

    public function driver(): FakeOcrDriver
    {
        return $this->fakeDriver;
    }
}
