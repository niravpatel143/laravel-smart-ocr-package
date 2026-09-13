<?php

namespace LaravelSmartOCR\Http;

use LaravelSmartOCR\Exceptions\OCRException;

/**
 * Minimal HTTP client using PHP's built-in curl extension.
 * No third-party packages required.
 */
class CurlClient
{
    private array $defaultHeaders;
    private int $timeout;
    private bool $verifySsl;

    public function __construct(array $defaultHeaders = [], int $timeout = 60, bool $verifySsl = true)
    {
        if (!extension_loaded('curl')) {
            throw new OCRException('PHP curl extension is required but not loaded. Enable it in php.ini.');
        }

        $this->defaultHeaders = $defaultHeaders;
        $this->timeout        = $timeout;
        $this->verifySsl      = $verifySsl;
    }

    public function post(string $url, array $payload, array $headers = []): array
    {
        $body = json_encode($payload);

        $allHeaders = array_merge($this->defaultHeaders, $headers);
        $allHeaders[] = 'Content-Type: application/json';
        $allHeaders[] = 'Content-Length: ' . strlen($body);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $response   = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new OCRException("HTTP request failed: {$curlError}");
        }

        $decoded = json_decode($response, true);

        if ($httpStatus >= 400) {
            $message = $decoded['error']['message']
                ?? $decoded['message']
                ?? "HTTP {$httpStatus} error";
            throw new OCRException("API error ({$httpStatus}): {$message}");
        }

        if (!is_array($decoded)) {
            throw new OCRException("Invalid JSON response from API.");
        }

        return $decoded;
    }
}
