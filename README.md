# Laravel Smart OCR — Image to Text, PDF & Document Data Extraction

[![Latest Stable Version](https://img.shields.io/packagist/v/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![Total Downloads](https://img.shields.io/packagist/dt/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![License](https://img.shields.io/packagist/l/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![PHP](https://img.shields.io/badge/PHP-%5E8.0-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-9%2F10%2F11%2F12%2F13-red)](https://laravel.com/)

**One facade. Four drivers. Zero Guzzle.** Extract text from images, scanned PDFs, invoices, receipts, and contracts using whichever engine fits your project — **Tesseract** (free, offline), **Claude Vision**, **OpenAI GPT-4o Vision**, or native **PDF text extraction** — all behind a single `SmartOCR::` interface.

---

## Demo

> **Full feature walkthrough** — Simple Text extraction, Batch processing, Multi-language OCR, Document Detection, AI Cleanup, Templates, Workflows, and URL Security.

https://github.com/user-attachments/assets/cf248862-b7cb-4504-8969-4e3745fd0e7c

---

## Table of Contents

- [Architecture](#architecture)
- [30-Second Quick Start](#30-second-quick-start)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Drivers](#drivers)
  - [PDF Text](#pdf-text-driver)
  - [Tesseract OCR](#tesseract-ocr-driver)
  - [Claude Vision](#claude-vision-driver)
  - [OpenAI Vision](#openai-vision-driver)
- [Basic Usage](#basic-usage)
- [Structured Extraction](#structured-extraction)
  - [Document Templates](#document-templates)
  - [AI Cleanup Service](#ai-cleanup-service)
- [Advanced Usage](#advanced-usage)
  - [Extract Tables](#extract-tables)
  - [Extract Barcodes and QR Codes](#extract-barcodes-and-qr-codes)
  - [Remote URLs](#remote-urls)
  - [Custom Drivers](#custom-drivers)
- [Security](#security)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

---

## Architecture

The package is built around a single `OCRManager` that exposes a unified driver interface. You pick the driver per call — or set a default in `.env`. All four drivers implement the same `OCRDriver` contract so you can swap engines without touching your application code.

```
SmartOCR facade
    └── OCRManager
            ├── driver('pdf')        → PdfTextDriver       (smalot/pdfparser, free, no binary)
            ├── driver('tesseract')  → TesseractDriver      (local binary, free, offline)
            ├── driver('claude')     → ClaudeVisionDriver   (Anthropic API, images + scans)
            └── driver('openai')     → OpenAIVisionDriver   (OpenAI GPT-4o, images + scans)
```

Supporting services — `TemplateManager`, `AICleanupService`, `DocumentParser` — layer on top of any driver result.

---

## 30-Second Quick Start

```bash
composer require laravelsmartocr/laravel-smart-ocr
php artisan vendor:publish --tag=smart-ocr-config
```

```php
use LaravelSmartOCR\Facades\SmartOCR;

// Free — extract text from a digital PDF (no API key, no binary)
$result = SmartOCR::driver('pdf')->extract($request->file('doc')->getPathname());
echo $result['text'];

// Free — extract text from an image via local Tesseract
$text = SmartOCR::freeText($request->file('image'));

// AI — extract text from any image using Claude Vision
$result = SmartOCR::driver('claude')->extract($request->file('scan')->getPathname());
echo $result['text'];
```

---

## Features

- **Four built-in drivers** — PDF parser (free), Tesseract (free/offline), Claude Vision, OpenAI GPT-4o
- **Image to text** — JPG, PNG, TIFF, BMP, WebP, GIF
- **PDF text extraction** — digital PDFs (PDF driver) and scanned PDFs (Tesseract + Ghostscript)
- **Table extraction** — `extractTable()` on every driver returns rows × columns
- **Barcode & QR code decoding** — via Claude or OpenAI vision drivers
- **Multi-language OCR** — 100+ languages via Tesseract language packs
- **Template-based field extraction** — define regex patterns per document type, reuse across scans
- **AI cleanup & structuring** — `AICleanupService` fixes typos, maps fields, structures output
- **SSRF protection** — 14 private/reserved CIDR blocks, scheme whitelist, redirect validation
- **MIME detection from bytes** — ignores filename/extension (`.tmp` uploads work out of the box)
- **Zero Guzzle** — pure PHP built-in `curl`
- **Laravel 9 / 10 / 11 / 12 / 13** compatible, PHP 8.0+

### Use Cases

| Use Case | How |
|---|---|
| Extract text from any uploaded image | `SmartOCR::freeText($file)` — one line, free |
| Parse a digital PDF | `SmartOCR::driver('pdf')->extract($path)` |
| Scan invoices → extract fields | `SmartOCR::extractWithTemplate($path, $templateId)` |
| Read barcodes / QR codes | `SmartOCR::driver('claude')->extractBarcode($path)` |
| AI-cleanup raw OCR output | `app(AICleanupService::class)->clean($result)` |
| Multi-language document processing | Tesseract driver with `language` option |
| Remote document fetch | `app(DocumentParser::class)->parse($url)` |

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^8.0 |
| Laravel | 9.x – 13.x |
| smalot/pdfparser | ^2.0 *(PDF driver — installed automatically)* |
| Tesseract binary | Any recent *(Tesseract driver only)* |
| Ghostscript binary | Any recent *(Tesseract driver + PDF input only)* |

---

## Installation

```bash
composer require laravelsmartocr/laravel-smart-ocr
```

Laravel auto-discovers the service provider. Publish the config:

```bash
php artisan vendor:publish --tag=smart-ocr-config
```

Publish and run migrations (required only if you use templates):

```bash
php artisan vendor:publish --tag=smart-ocr-migrations
php artisan migrate
```

---

## Configuration

Set your driver and API keys in `.env`:

```env
# Default driver: pdf | tesseract | claude | openai
SMART_OCR_DRIVER=pdf

# Claude (Anthropic) — required for claude driver
ANTHROPIC_API_KEY=sk-ant-...

# OpenAI — required for openai driver
OPENAI_API_KEY=sk-...

# Tesseract — auto-detected from PATH if not set
TESSERACT_BINARY="C:\Program Files\Tesseract-OCR\tesseract.exe"
```

Key options in `config/smart-ocr.php`:

```php
return [
    'default' => env('SMART_OCR_DRIVER', 'pdf'),

    'drivers' => [
        'claude'    => ['api_key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-opus-4-7', 'timeout' => 60],
        'openai'    => ['api_key' => env('OPENAI_API_KEY'),    'model' => 'gpt-4o',           'timeout' => 60],
        'pdf'       => ['max_pages' => 100],
        'tesseract' => ['binary' => env('TESSERACT_BINARY', ''), 'language' => 'eng', 'timeout' => 60],
    ],

    'remote_urls' => [
        'allow_remote_urls' => false, // must opt-in explicitly
        'max_download_size' => 10 * 1024 * 1024,
    ],
];
```

---

## Drivers

### PDF Text Driver

Extracts embedded text from **digital PDFs** using `smalot/pdfparser`. No binary required, completely free. Returns an error if the PDF has no text layer (scanned PDF) — use Tesseract or a vision driver in that case.

```php
$result = SmartOCR::driver('pdf')->extract('/path/to/document.pdf');

echo $result['text'];
echo $result['metadata']['page_count'];
```

### Tesseract OCR Driver

Calls the Tesseract binary directly via `proc_open()` with a configurable timeout. Supports JPEG, PNG, TIFF, BMP. For PDFs, Ghostscript converts the first page to an image first.

**Install Tesseract:**

```bash
# Ubuntu / Debian
sudo apt-get install tesseract-ocr

# macOS
brew install tesseract

# Windows — download from:
# https://github.com/UB-Mannheim/tesseract/wiki
```

```php
$result = SmartOCR::driver('tesseract')->extract('/path/to/scan.png', [
    'language' => 'eng', // any installed Tesseract language pack
    'psm'      => 6,     // page segmentation mode
]);
```

### Claude Vision Driver

Uses the Anthropic Claude API. Supports JPEG, PNG, GIF, WebP up to 5 MB. Best for complex scanned documents where layout matters.

**Requires:** `ANTHROPIC_API_KEY`

```php
$result = SmartOCR::driver('claude')->extract('/path/to/invoice.jpg');
```

### OpenAI Vision Driver

Uses OpenAI GPT-4o vision. Supports JPEG, PNG, GIF, WebP up to 20 MB.

**Requires:** `OPENAI_API_KEY`

```php
$result = SmartOCR::driver('openai')->extract('/path/to/image.png');
```

---

## Basic Usage

### Facade — extract text

```php
use LaravelSmartOCR\Facades\SmartOCR;

// Returns full result array
$result = SmartOCR::driver('pdf')->extract('/path/to/document.pdf');

echo $result['text'];         // extracted text
echo $result['confidence'];   // 0.0–1.0
print_r($result['metadata']); // engine, processing_time, page_count, etc.

// Returns just the text string (uses default driver)
$text = SmartOCR::getText('/path/to/document.pdf');

// Always uses Tesseract — free, offline, no API key
$text = SmartOCR::freeText('/path/to/scan.png');
$text = SmartOCR::freeText('/path/to/scan.png', 'fra'); // French
```

### extractText() — same as extract()

```php
// extractText() is an alias for extract() — both return the same array
$result = SmartOCR::driver('tesseract')->extractText('/path/to/image.jpg');
echo $result['text'];
```

### File uploads (UploadedFile)

Uploaded files have a `.tmp` extension — the package reads MIME type from file bytes, so no renaming is needed:

```php
public function upload(Request $request): JsonResponse
{
    $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);

    $path   = $request->file('file')->getPathname(); // .tmp extension is fine
    $result = SmartOCR::driver('pdf')->extract($path);

    return response()->json($result);
}
```

### Dependency injection

```php
use LaravelSmartOCR\Services\OCRManager;

class InvoiceController extends Controller
{
    public function __construct(private OCRManager $ocr) {}

    public function process(Request $request): JsonResponse
    {
        $path   = $request->file('invoice')->getPathname();
        $result = $this->ocr->driver('claude')->extract($path);
        return response()->json($result);
    }
}
```

---

## Structured Extraction

### Document Templates

Templates define named fields with regex patterns. Once created, apply them to any extracted text to get a structured key–value result.

**Create a template:**

```php
use LaravelSmartOCR\Services\TemplateManager;

app(TemplateManager::class)->create([
    'name'   => 'Invoice',
    'type'   => 'invoice',
    'fields' => [
        [
            'key'     => 'invoice_number',
            'label'   => 'Invoice Number',
            'type'    => 'string',
            'pattern' => '/Invoice\s*#?\s*(\d+)/i',
        ],
        [
            'key'     => 'total',
            'label'   => 'Total Amount',
            'type'    => 'currency',
            'pattern' => '/Total\s*:?\s*\$?([\d,]+\.?\d*)/i',
        ],
        [
            'key'   => 'date',
            'label' => 'Invoice Date',
            'type'  => 'date',
        ],
    ],
]);
```

**Apply a template:**

```php
// Extracts with default driver, then applies template field patterns
$result = SmartOCR::extractWithTemplate('/path/to/invoice.pdf', $templateId);

echo $result['fields']['invoice_number']['value'];
echo $result['fields']['total']['confidence']; // 0.0–1.0
```

**Auto-detect template from content:**

```php
$raw      = SmartOCR::driver('pdf')->extract('/path/to/document.pdf');
$template = app(TemplateManager::class)->findTemplateByContent($raw['text']);

if ($template) {
    $structured = app(TemplateManager::class)->applyTemplate($raw, $template->id);
}
```

**Export and import templates as JSON:**

```php
// Export
$json = app(TemplateManager::class)->exportTemplate($templateId);
file_put_contents(storage_path('app/invoice-template.json'), $json);

// Import — path must be inside storage_path() for security
$template = app(TemplateManager::class)->importTemplate(
    storage_path('app/invoice-template.json')
);
```

### AI Cleanup Service

Post-process raw OCR output to fix typos, normalise values, map fields, and structure data. Basic rules require no API key.

```php
use LaravelSmartOCR\Services\AICleanupService;

$raw = SmartOCR::driver('tesseract')->extract('/path/to/scan.jpg');

// Basic rules — no API key needed
// Fixes common OCR errors (rn→m, 0↔O) and normalises field types
$cleaned = app(AICleanupService::class)->clean($raw);

// AI-powered cleanup via OpenAI or Anthropic
$cleaned = app(AICleanupService::class)->clean($raw, [
    'provider'      => 'openai',   // 'openai' | 'anthropic'
    'document_type' => 'invoice',
]);

echo $cleaned['text'];
```

**Correct typos only:**

```php
$fixed = app(AICleanupService::class)->correctTypos($raw['text']);
```

**Structure data by document type:**

```php
$structured = app(AICleanupService::class)->structureData($raw, 'invoice');
// Returns normalised fields inferred from document type
```

**Field mapping with fuzzy matching:**

```php
$mapped = app(AICleanupService::class)->mapFields($raw, [
    'invoice_id'   => 'invoice_number',
    'vendor'       => [
        'alternatives' => ['vendor_name', 'supplier', 'from'],
        'default'      => 'Unknown',
    ],
    'total_amount' => ['field' => 'total', 'transform' => 'trim'],
]);
```

---

## Advanced Usage

### Extract Tables

All four drivers support `extractTable()`. Returns an array of rows; each row is an array of cell strings.

```php
$result = SmartOCR::driver('tesseract')->extractTable('/path/to/table-scan.png');

foreach ($result['table'] as $row) {
    echo implode(' | ', $row) . "\n";
}

// Raw text is also available
echo $result['raw_text'];
```

### Extract Barcodes and QR Codes

Barcode and QR code extraction is supported by the **Claude** and **OpenAI** drivers only.

```php
// Barcode
$result = SmartOCR::driver('claude')->extractBarcode('/path/to/barcode.png');
// $result['barcodes'] — array of decoded values

// QR code
$result = SmartOCR::driver('openai')->extractQRCode('/path/to/qrcode.png');
// $result['barcodes'] — array of decoded values
```

### Remote URLs

Remote URL fetching is **disabled by default** to prevent SSRF. Enable explicitly in config:

```php
// config/smart-ocr.php
'remote_urls' => [
    'allow_remote_urls' => true,
],
```

```php
use LaravelSmartOCR\Services\DocumentParser;

$result = app(DocumentParser::class)->parse('https://example.com/invoice.pdf');

if ($result['success']) {
    echo $result['text'];
} else {
    echo $result['error']; // SSRF block | HTTP error | size exceeded
}
```

Private IPs, loopback addresses (`127.x`, `::1`), and cloud metadata endpoints (`169.254.x`) are always blocked regardless of config.

### Custom Drivers

Implement the `OCRDriver` contract and register via `extend()` in a service provider:

```php
namespace LaravelSmartOCR\Contracts;

interface OCRDriver
{
    public function extract($document, array $options = []): array;
    public function extractText($document, array $options = []): array;
    public function extractTable($document, array $options = []): array;
    public function extractBarcode($document, array $options = []): array;
    public function extractQRCode($document, array $options = []): array;
    public function getSupportedLanguages(): array;
    public function getSupportedFormats(): array;
}
```

```php
use LaravelSmartOCR\Facades\SmartOCR;
use App\OCR\MyCustomDriver;

// AppServiceProvider::boot()
SmartOCR::extend('my-driver', function ($app) {
    return new MyCustomDriver(
        $app['config']->get('smart-ocr.drivers.my-driver', [])
    );
});

SmartOCR::driver('my-driver')->extract('/path/to/file.jpg');
```

---

## Security

| Protection | Details |
|---|---|
| **SSRF prevention** | 14 private/reserved CIDR ranges blocked; only `http` and `https` schemes allowed |
| **Redirect validation** | Every redirect target is re-validated before cURL follows it |
| **Download size cap** | Configurable `max_download_size` (default 10 MB), enforced per-chunk via `CURLOPT_WRITEFUNCTION` |
| **MIME from bytes** | File type detected via `finfo(FILEINFO_MIME_TYPE)`, never from filename or extension |
| **Path traversal** | `importTemplate()` enforces `realpath()` boundary inside `storage_path()` |
| **ReDoS protection** | User-supplied regex patterns run with `@preg_match()` and explicit return-value check |
| **Process timeout** | Tesseract runs via `proc_open()` with `proc_terminate()` on configurable timeout |
| **Filename sanitisation** | Downloaded filenames stripped to `[a-zA-Z0-9._-]` characters only |
| **SSL verification** | `CURLOPT_SSL_VERIFYPEER = true` always enforced |
| **No Guzzle** | All HTTP via PHP built-in `curl` — minimal third-party attack surface |

**Reporting a vulnerability:** Please do **not** open a public GitHub issue. Email `support@laravelsmartocr.com` with a description and reproduction steps. We aim to respond within 48 hours and will coordinate a fix before any public disclosure.

---

## Testing

```bash
composer install
./vendor/bin/phpunit
```

The package ships with unit and integration tests using Orchestra Testbench. No external API calls are made during tests.

---

## Changelog

### v1.0.2

- Added Laravel 13 support (`illuminate/support ^13.0`)
- Extended `orchestra/testbench` constraint to `^9.0|^10.0`

### v1.0.1

- Added `extractText()` as an alias for `extract()` on all four drivers and `OCRManager`
- Added `extractText()` to the `OCRDriver` contract

### v1.0.0

- Initial stable release

### v2.0.0 (pre-release)

**New drivers**
- `ClaudeVisionDriver` — Anthropic Messages API, PHP built-in curl
- `OpenAIVisionDriver` — GPT-4o vision API, PHP built-in curl
- `PdfTextDriver` — smalot/pdfparser, no binary required
- Rewrote `TesseractDriver` — direct `proc_open()`, no PHP wrapper library

**Removed dependencies**
- Removed `guzzlehttp/guzzle` — replaced with `CurlClient` (PHP built-in curl)
- Removed `thiagoalessio/tesseract_ocr` PHP wrapper
- Removed `intervention/image`

**Security hardening**
- SSRF protection — 14 CIDR block ranges, scheme whitelist, redirect validation
- MIME detection from file bytes
- Path traversal fix in `TemplateManager::importTemplate()`
- ReDoS protection for user-supplied regex patterns
- `proc_open()` + `proc_terminate()` timeout for Tesseract

---

## License

The MIT License (MIT). Copyright (c) 2024 Laravel Smart OCR Team.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
