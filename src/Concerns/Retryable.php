<?php declare(strict_types=1);

namespace LaravelSmartOCR\Concerns;

use LaravelSmartOCR\Exceptions\AuthenticationException;
use LaravelSmartOCR\Exceptions\OcrTimeoutException;
use LaravelSmartOCR\Exceptions\ProviderException;
use LaravelSmartOCR\Exceptions\RateLimitException;
use LaravelSmartOCR\Exceptions\UnsupportedDocumentException;

trait Retryable
{
    /**
     * HTTP status codes that must NOT be retried — client errors that indicate
     * a permanent failure rather than a transient one.
     */
    private const NO_RETRY_HTTP_CODES = [400, 401, 403, 404, 413, 422];

    protected function withRetry(callable $callback, int $maxAttempts = 3, int $baseDelayMs = 500): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (AuthenticationException | UnsupportedDocumentException $e) {
                // Never retry auth/unsupported-doc errors
                throw $e;
            } catch (ProviderException $e) {
                // Do not retry permanent client-error HTTP codes
                if (in_array($e->getCode(), self::NO_RETRY_HTTP_CODES, true)) {
                    throw $e;
                }
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep($baseDelayMs * (2 ** $attempt) * 1000);
            } catch (RateLimitException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                // Honour the provider's retry-after header if available
                $waitMs = $e->retryAfter() > 0 ? $e->retryAfter() * 1_000_000 : $baseDelayMs * (2 ** $attempt) * 1000;
                usleep($waitMs);
            } catch (OcrTimeoutException $e) {
                // Network / connection timeouts are transient — retry
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep($baseDelayMs * (2 ** $attempt) * 1000);
            } catch (\Throwable $e) {
                // Retry unknown throwables (likely network errors) but not
                // anything with a no-retry HTTP code embedded in the message
                // (last-resort heuristic for drivers that don't throw typed exceptions)
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep($baseDelayMs * (2 ** $attempt) * 1000);
            }
        }
    }
}
