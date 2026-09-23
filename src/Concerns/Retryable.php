<?php declare(strict_types=1);

namespace LaravelSmartOCR\Concerns;

use LaravelSmartOCR\Exceptions\AuthenticationException;
use LaravelSmartOCR\Exceptions\OcrTimeoutException;
use LaravelSmartOCR\Exceptions\RateLimitException;
use LaravelSmartOCR\Exceptions\UnsupportedDocumentException;

trait Retryable
{
    protected function withRetry(callable $callback, int $maxAttempts = 3, int $baseDelayMs = 500): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (AuthenticationException | UnsupportedDocumentException $e) {
                throw $e; // Never retry auth/doc errors
            } catch (RateLimitException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $sleepMs = $e->retryAfter() * 1000;
                usleep($sleepMs * 1000);
            } catch (OcrTimeoutException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep($baseDelayMs * (2 ** $attempt) * 1000);
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep($baseDelayMs * (2 ** $attempt) * 1000);
            }
        }
    }
}
