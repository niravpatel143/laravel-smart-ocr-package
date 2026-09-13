<?php

namespace LaravelSmartOCR\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LaravelSmartOCR\Data\DocumentInput;
use LaravelSmartOCR\Exceptions\InvalidDocumentException;

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
        $this->connectTimeout  = (int) ($remote['connect_timeout'] ?? 5);
        $this->totalTimeout    = (int) ($remote['total_timeout'] ?? 30);
        $this->maxDownloadSize = (int) ($remote['max_download_size'] ?? 10 * 1024 * 1024);
    }

    /**
     * Download a remote URL to a temp file and return a DocumentInput.
     * Throws InvalidDocumentException on any security or network failure.
     */
    public function resolve(string $url): DocumentInput
    {
        // Validate URL before connecting
        $this->urlValidator->validate($url);

        $tempPath = $this->makeTempPath($url);

        try {
            $this->download($url, $tempPath);
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($tempPath) ?: 'application/octet-stream';
        $filename = basename(parse_url($url, PHP_URL_PATH) ?? 'remote-document');
        if ($filename === '') {
            $filename = 'remote-document';
        }

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
        $redirectValidator = $this->urlValidator;
        $maxDownload       = $this->maxDownloadSize;

        $stack = HandlerStack::create();

        // Validate every redirect target before following
        $stack->push(Middleware::mapRequest(function (Request $request) use ($redirectValidator) {
            $redirectValidator->validateRedirect((string) $request->getUri());
            return $request;
        }));

        $client = new Client([
            'handler'         => $stack,
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->totalTimeout,
            'allow_redirects' => [
                'max'             => 5,
                'strict'          => true,
                'referer'         => false,
                'track_redirects' => false,
                'on_redirect'     => function ($request, $response, $uri) use ($redirectValidator) {
                    $redirectValidator->validateRedirect((string) $uri);
                },
            ],
            'stream'     => true,
            'verify'     => true,
        ]);

        $response = $client->get($url);

        $contentLength = (int) ($response->getHeaderLine('Content-Length') ?: 0);
        if ($contentLength > $maxDownload) {
            throw new InvalidDocumentException(
                "Remote document Content-Length ({$contentLength} bytes) exceeds maximum allowed."
            );
        }

        $body    = $response->getBody();
        $written = 0;
        $handle  = fopen($tempPath, 'wb');

        if ($handle === false) {
            throw new InvalidDocumentException("Cannot write temporary file for remote document.");
        }

        try {
            while (! $body->eof()) {
                $chunk   = $body->read(8192);
                $written += strlen($chunk);

                if ($written > $maxDownload) {
                    throw new InvalidDocumentException(
                        "Remote document exceeds maximum download size ({$maxDownload} bytes)."
                    );
                }

                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    private function makeTempPath(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'tmp';

        return sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(16)) . '.' . $ext;
    }
}
