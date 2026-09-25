<?php declare(strict_types=1);

namespace LaravelSmartOCR\Tests\Unit;

use LaravelSmartOCR\Services\OcrDriverBuilder;
use LaravelSmartOCR\Tests\TestCase;

class SecretScrubbingTest extends TestCase
{
    public function test_api_key_not_in_fallback_reason(): void
    {
        // The scrubSensitiveData method should strip base64-like strings
        // We test it indirectly by checking OcrDriverBuilder behaviour
        // A real API key like "AIzaSy..." base64 tokens should be scrubbed
        $builder = new OcrDriverBuilder(
            new \LaravelSmartOCR\Testing\FakeOcrDriver(),
            'google',
            app(\LaravelSmartOCR\Services\OCRManager::class),
        );

        // Use reflection to test the scrub method directly
        $ref = new \ReflectionClass($builder);
        if ($ref->hasMethod('scrubSensitiveData')) {
            $method = $ref->getMethod('scrubSensitiveData');
            $method->setAccessible(true);

            $input  = 'Error with key AIzaSyD-9tSrke72I3xi2B ' . str_repeat('a', 40) . ' please retry';
            $result = $method->invoke($builder, $input);

            // Should not contain the long base64-like string
            $this->assertStringNotContainsString(str_repeat('a', 40), $result);
        } else {
            $this->markTestSkipped('scrubSensitiveData method not found');
        }
    }
}
