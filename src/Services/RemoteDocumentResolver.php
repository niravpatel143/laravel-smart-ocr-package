<?php

namespace LaravelSmartOCR\Services;

use LaravelSmartOCR\Data\DocumentInput;
use LaravelSmartOCR\Exceptions\InvalidDocumentException;

/**
 * Downloads remote documents to a local temp file using PHP built-in curl.
 * No third-party HTTP library required.
 */
class RemoteDocumentResolver
{
    private UrlSecurityValidator $urlValidator;
    private int $connectTimeout;
    private int $totalTimeout;
    private int $maxDownloadSize;

    public function __construct(UrlSecurityValidator $urlValidator, array $config = [])
    {
        $this->urlValidator    = $urlValidator;
        $remote                = $config['remote_urls'] ?? [];
        $this->connectTimeout  = (int) ($remote['connect_timeout']   ?? 5);
        $this->totalTimeout    = (int) ($remote['total_timeout']     ?? 30);
        $this->maxDownloadSize = (int) ($remote['max_download_size'] ?? 10 * 1024 * 1024);
    }

    public function resolve(string $url): DocumentInput
    {
        $this->urlValidator->validate($url);

        $tempPath = $this->makeTempPath();

        try {
            $this->download($url, $tempPath);
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($tempPath) ?: 'application/octet-stream';

        // Sanitise filename — only safe characters, no path traversal
        $rawName  = basename(parse_url($url, PHP_URL_PATH) ?? '');
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $rawName) ?: 'remote-document';

        return DocumentInput::fromDownloadedUrl(
            remoteUrl: $url,
            localTempPath: $tempPath,
            originalFilename: $filename,
            mimeType: $mimeType,
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function download(string $url, string $tempPath): void
    {
        $maxDownload    = $this->maxDownloadSize;
        $urlValidator   = $this->urlValidator;
        $written        = 0;

        $handle = fopen($tempPath, 'wb');
        if ($handle === false) {
            throw new InvalidDocumentException("Cannot create temporary file for remote document.");
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->totalTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LaravelSmartOCR/1.0',

            // Validate redirect targets before following
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use ($urlValidator) {
                if (stripos($header, 'Location:') === 0) {
                    $redirectUrl = trim(substr($header, 9));
                    if ($redirectUrl) {
                        $urlValidator->validateRedirect($redirectUrl);
                    }
                }
                return strlen($header);
            },

            // Write chunks directly to file — validate size limit per chunk
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($handle, $maxDownload, &$written) {
                $written += strlen($chunk);
                if ($written > $maxDownload) {
                    return -1; // Abort curl — triggers CURLE_WRITE_ERROR
                }
                fwrite($handle, $chunk);
                return strlen($chunk);
            },
        ]);

        $success   = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);
        fclose($handle);

        if ($curlErrNo === CURLE_WRITE_ERROR || $written > $maxDownload) {
            throw new InvalidDocumentException(
                "Remote document exceeds maximum download size ({$maxDownload} bytes)."
            );
        }

        if (!$success || $curlError) {
            throw new InvalidDocumentException("Failed to download remote document: {$curlError}");
        }

        if ($httpCode >= 400) {
            throw new InvalidDocumentException("Remote server returned HTTP {$httpCode}.");
        }
    }

    private function makeTempPath(): string
    {
        // Fully random name — never derived from user input
        return sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(16)) . '.tmp';
    }
}
