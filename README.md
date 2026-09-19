# Laravel Smart OCR — Image to Text, PDF & Document Data Extraction

[![Latest Stable Version](https://img.shields.io/packagist/v/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![Total Downloads](https://img.shields.io/packagist/dt/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![License](https://img.shields.io/packagist/l/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![PHP](https://img.shields.io/badge/PHP-%5E8.0-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-9%2F10%2F11%2F12-red)](https://laravel.com/)

**The most complete OCR package for Laravel.** Extract text from images, scanned PDFs, invoices, receipts, contracts, and more — using **Tesseract OCR** (free, offline), **Claude Vision**, **OpenAI GPT-4o Vision**, or native **PDF text extraction**. Supports barcode scanning, QR code reading, table extraction, multi-language OCR (100+ languages), AI-powered cleanup, and template-based field extraction. Zero Guzzle dependency — pure PHP `curl`.

---

## Demo

> **Full feature walkthrough** — Simple Text extraction, Batch processing, Multi-language OCR, Document Detection, AI Cleanup, Templates, Workflows, and URL Security.

https://github.com/user-attachments/assets/cf248862-b7cb-4504-8969-4e3745fd0e7c

---

## Table of Contents

- [Demo](#demo)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Drivers](#drivers)
  - [Claude Vision](#claude-vision-driver)
  - [OpenAI Vision](#openai-vision-driver)
  - [PDF Text](#pdf-text-driver)
  - [Tesseract OCR](#tesseract-ocr-driver)
- [Basic Usage](#basic-usage)
- [Advanced Usage](#advanced-usage)
  - [Extract Tables](#extract-tables)
  - [Extract Barcodes and QR Codes](#extract-barcodes-and-qr-codes)
  - [Document Templates](#document-templates)
  - [AI Cleanup Service](#ai-cleanup-service)
  - [Remote URLs](#remote-urls)
  - [Custom Drivers](#custom-drivers)
- [Security](#security)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

---

## Features

- **Image to text extraction** — JPG, PNG, TIFF, BMP, WebP, GIF
- **PDF text extraction** — digital PDFs (no binary needed) and scanned PDFs via Tesseract
- **Four built-in OCR drivers** — Tesseract (free/offline), Claude Vision, OpenAI GPT-4o, PDF parser
- **Invoice & receipt parser** — auto-detect document type, extract fields, amounts, dates
- **Barcode & QR code reader** — decode 1D/2D barcodes from images via AI vision drivers
- **Table extraction** — parse structured tables from scanned documents
- **Multi-language OCR** — 100+ languages via Tesseract (English, Hindi, Arabic, Chinese, Japanese…)
- **AI-powered cleanup** — fix OCR typos, normalise fields, structure data via OpenAI or Claude
- **Template-based field extraction** — define regex patterns per document type, reuse across scans
- **Batch document processing** — process multiple files in one call
- **Document type detection** — auto-classify as invoice, receipt, contract, purchase order, shipping
- **SSRF protection** — blocks private IPs, loopback, and cloud metadata endpoints
- **Zero Guzzle dependency** — pure PHP built-in `curl`
- **Laravel 9 / 10 / 11 / 12** compatible, PHP 8.0+

## Use Cases

| Use Case | How |
|---|---|
| **Scan invoices → extract line items** | Tesseract or Claude driver + invoice template |
| **Receipt OCR for expense tracking** | `SmartOCR::getText($uploadedFile)` — free, offline |
| **Extract text from any image** | `SmartOCR::freeText($file)` — one line of code |
| **Read barcodes / QR codes** | `SmartOCR::driver('claude')->extractBarcode($image)` |
| **Parse scanned contracts** | Tesseract + template field extraction |
| **Multi-language document processing** | Tesseract with 100+ language packs |
| **AI document cleanup & structuring** | `AICleanupService::clean()` via OpenAI or Anthropic |
| **Digitise physical documents** | Any image format → structured JSON output |
| **Automate purchase order entry** | Document parser + PO template |
| **PDF data extraction** | PDF driver (no binary) or Tesseract (scanned PDFs) |

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^8.0 |
| Laravel / Lumen | 9.x – 12.x |
| smalot/pdfparser | ^2.0 *(PDF Text driver)* |
| Tesseract binary | Any recent *(Tesseract driver only)* |
| Ghostscript binary | Any recent *(Tesseract + PDF input only)* |

---

## Installation

```bash
composer require laravelsmartocr/laravel-smart-ocr
```

Laravel auto-discovers the service provider. Publish the configuration file:

```bash
php artisan vendor:publish --tag=smart-ocr-config
```

Publish and run database migrations (required for template features):

```bash
php artisan vendor:publish --tag=smart-ocr-migrations
php artisan migrate
```

---

## Configuration

Set values in your `.env` file:

```env
# Default driver: claude | openai | pdf | tesseract
SMART_OCR_DRIVER=claude

# Claude (Anthropic)
ANTHROPIC_API_KEY=sk-ant-...

# OpenAI
OPENAI_API_KEY=sk-...

# Tesseract — auto-detected from PATH if not set
TESSERACT_BINARY="C:\Program Files\Tesseract-OCR\tesseract.exe"
```

Full config reference (`config/smart-ocr.php`):

```php
return [
    'default' => env('SMART_OCR_DRIVER', 'claude'),

    'drivers' => [
        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model'   => 'claude-opus-4-7',
            'timeout' => 60,
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model'   => 'gpt-4o',
            'timeout' => 60,
        ],
        'pdf' => [
            'max_pages' => 100,
        ],
        'tesseract' => [
            'binary'   => env('TESSERACT_BINARY', ''),
            'language' => 'eng',
            'psm'      => 3,
            'timeout'  => 60,
        ],
    ],

    'remote_urls' => [
        'enabled'           => false,    // must opt-in explicitly
        'connect_timeout'   => 5,
        'total_timeout'     => 30,
        'max_download_size' => 10485760, // 10 MB
    ],
];
```

---

## Drivers

### Claude Vision Driver

Uses the Anthropic Claude API to analyse images. Supports JPEG, PNG, GIF, and WebP. Does **not** support PDF — use the PDF Text driver for PDF files.

**Requires:** `ANTHROPIC_API_KEY`

```php
SmartOCR::driver('claude')->extract('/path/to/image.jpg');
```

### OpenAI Vision Driver

Uses OpenAI GPT-4o vision capabilities. Supports JPEG, PNG, GIF, and WebP up to 20 MB.

**Requires:** `OPENAI_API_KEY`

```php
SmartOCR::driver('openai')->extract('/path/to/image.png');
```

### PDF Text Driver

Extracts embedded text from digital PDFs using `smalot/pdfparser`. No external binary required. Gracefully returns an empty result when a PDF has no text layer (scanned PDF).

```php
SmartOCR::driver('pdf')->extract('/path/to/document.pdf');
```

### Tesseract OCR Driver

Calls the Tesseract binary directly via `proc_open()` with a configurable timeout. Supports JPEG, PNG, TIFF, BMP, and PDF (PDF-to-image conversion requires Ghostscript).

**Install Tesseract:**

```bash
# Ubuntu / Debian
sudo apt-get install tesseract-ocr

# macOS
brew install tesseract

# Windows — download installer from:
# https://github.com/UB-Mannheim/tesseract/wiki
```

```php
SmartOCR::driver('tesseract')->extract('/path/to/scan.png', [
    'language' => 'eng',
    'psm'      => 6,
]);
```

---

## Basic Usage

### Facade

```php
use LaravelSmartOCR\Facades\SmartOCR;

$result = SmartOCR::extract('/path/to/document.jpg');

echo $result['text'];         // extracted text
echo $result['confidence'];   // 0.0–1.0
print_r($result['metadata']); // engine, model, processing_time, etc.
```

### Dependency Injection

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

### File Upload

Uploaded files have a `.tmp` extension — the package detects their real MIME type from file bytes, so no renaming is needed:

```php
public function upload(Request $request): JsonResponse
{
    $request->validate(['file' => 'required|file|max:20480']);

    $path   = $request->file('file')->getPathname(); // .tmp is fine
    $result = SmartOCR::extract($path);

    return response()->json($result);
}
```

---

## Advanced Usage

### Extract Tables

```php
$result = SmartOCR::driver('tesseract')->extractTable('/path/to/table-scan.png');

// $result['table'] is an array of rows; each row is an array of cell strings
foreach ($result['table'] as $row) {
    echo implode(' | ', $row) . PHP_EOL;
}
```

### Extract Barcodes and QR Codes

Barcode and QR code extraction requires a vision-capable driver (Claude or OpenAI).

```php
$result = SmartOCR::driver('claude')->extractBarcode('/path/to/barcode.png');
// $result['barcodes'] — array of decoded barcode values

$result = SmartOCR::driver('openai')->extractQRCode('/path/to/qrcode.png');
// $result['barcodes'] — array of decoded QR code values
```

### Document Templates

Templates let you define named fields with regex patterns and position hints, then apply them to extracted text to produce structured key–value output.

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
            'label'   => 'Total',
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
$result = SmartOCR::extractWithTemplate('/path/to/invoice.pdf', $templateId);

echo $result['fields']['invoice_number']['value'];
echo $result['fields']['total']['confidence']; // 0.0–1.0
```

**Export and import templates as JSON:**

```php
// Export
$json = app(TemplateManager::class)->exportTemplate($templateId);
file_put_contents(storage_path('app/invoice-template.json'), $json);

// Import — file must be inside storage_path() for security
$template = app(TemplateManager::class)->importTemplate(
    storage_path('app/invoice-template.json')
);
```

### AI Cleanup Service

Post-process raw OCR output to fix typos, normalise values, and structure data.

```php
use LaravelSmartOCR\Services\AICleanupService;

$raw     = SmartOCR::extract('/path/to/scan.jpg');
$cleaned = app(AICleanupService::class)->clean($raw, [
    'provider'      => 'openai',   // 'openai' | 'anthropic' | omit for basic rules
    'document_type' => 'invoice',
]);
```

**Basic rules (no API key required):**

```php
$cleaned = app(AICleanupService::class)->clean($raw);
// Applies common OCR fixes (rn→m, 0↔O) and field-type normalisation
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

### Remote URLs

Remote URL fetching is **disabled by default**. To enable:

```php
// config/smart-ocr.php
'remote_urls' => [
    'enabled' => true,
],
```

```php
use LaravelSmartOCR\Services\DocumentParser;

$result = app(DocumentParser::class)->parse('https://example.com/invoice.pdf');

if ($result['success']) {
    echo $result['text'];
} else {
    echo $result['error']; // e.g. SSRF block, HTTP error, size exceeded
}
```

Private IPs, loopback addresses, and cloud metadata endpoints are always blocked.

### Custom Drivers

Register a custom driver with the `extend()` method in a service provider:

```php
use LaravelSmartOCR\Facades\SmartOCR;
use App\OCR\MyCustomDriver;

// AppServiceProvider::boot()
SmartOCR::extend('my-driver', function ($app) {
    return new MyCustomDriver(
        $app['config']->get('smart-ocr.drivers.my-driver', [])
    );
});

// Use it
SmartOCR::driver('my-driver')->extract('/path/to/file.jpg');
```

Your driver must implement `LaravelSmartOCR\Contracts\OCRDriver`:

```php
namespace LaravelSmartOCR\Contracts;

interface OCRDriver
{
    public function extract($document, array $options = []): array;
    public function extractTable($document, array $options = []): array;
    public function extractBarcode($document, array $options = []): array;
    public function extractQRCode($document, array $options = []): array;
    public function getSupportedLanguages(): array;
    public function getSupportedFormats(): array;
}
```

---

## Security

This package is built with security as a first-class concern.

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
| **SSL verification** | `CURLOPT_SSL_VERIFYPEER = true` always enforced; not configurable |
| **No Guzzle** | All HTTP via PHP built-in `curl` — minimal third-party attack surface |

**Reporting a vulnerability:** Please do **not** open a public GitHub issue. Email `support@laravelsmartocr.com` with a description and reproduction steps. We aim to respond within 48 hours and will coordinate a fix before any public disclosure.

---

## Testing

```bash
composer install
./vendor/bin/phpunit
```

The package ships with unit and integration tests using Orchestra Testbench. External API calls are not made during tests.

---

## Changelog

### v2.0.0

**New drivers**
- Added `ClaudeVisionDriver` — Anthropic Messages API, PHP built-in curl
- Added `OpenAIVisionDriver` — GPT-4o vision API, PHP built-in curl
- Added `PdfTextDriver` — smalot/pdfparser, no binary required
- Rewrote `TesseractDriver` — direct `proc_open()`, no PHP wrapper library

**Removed dependencies**
- Removed `guzzlehttp/guzzle` — replaced with `CurlClient` (PHP built-in curl)
- Removed `thiagoalessio/tesseract_ocr` PHP wrapper
- Removed `intervention/image`

**Security fixes**
- SSRF protection — 14 CIDR block ranges, scheme whitelist, redirect validation
- MIME detection from file bytes — fixes `.tmp` uploaded file handling
- Path traversal fix in `TemplateManager::importTemplate()`
- ReDoS protection for user-supplied regex patterns
- `proc_open()` + `proc_terminate()` timeout for Tesseract (replaces hanging `exec()`)
- Filename sanitisation for remotely downloaded files

**Bug fixes**
- `AICleanupService` — removed broken `Http` facade (Guzzle), now uses `CurlClient`
- `AICleanupService::cleanWithAnthropic()` — fully implemented
- `AICleanupService::cleanWithBasicRules()` — fixed dangling reference after `foreach &$field`
- `OCRManager` — removed stale `class_exists` check for removed library

### v1.0.0

- Initial release

---

## Keywords

`laravel-ocr` `image-to-text` `pdf-text-extraction` `tesseract-laravel` `claude-vision` `openai-vision` `gpt4o-ocr` `invoice-parser` `receipt-ocr` `document-scanner` `barcode-reader` `qr-code-laravel` `multi-language-ocr` `ai-document-extraction` `php-ocr` `scan-to-text` `laravel-pdf-parser` `laravel-ai` `optical-character-recognition` `laravel-document-processing`

---

## License

The MIT License (MIT)

Copyright (c) 2024 Laravel Smart OCR Team

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
