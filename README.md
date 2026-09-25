# Laravel Smart OCR — Multi-Provider OCR Platform

[![Latest Stable Version](https://img.shields.io/packagist/v/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![Total Downloads](https://img.shields.io/packagist/dt/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![License](https://img.shields.io/packagist/l/laravelsmartocr/laravel-smart-ocr.svg)](https://packagist.org/packages/laravelsmartocr/laravel-smart-ocr)
[![PHP](https://img.shields.io/badge/PHP-%5E8.0-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-9%2F10%2F11%2F12%2F13-red)](https://laravel.com/)
[![Version](https://img.shields.io/badge/version-3.0.0-brightgreen)](https://github.com/laravelsmartocr/laravel-smart-ocr/releases)

**One API. Nine drivers. One normalized result.** Extract text, tables, bounding boxes, and form fields from images, scanned PDFs, invoices, and contracts — using **Tesseract**, **Google Cloud Vision**, **AWS Textract**, **Azure AI Vision**, **Claude Vision**, **OpenAI GPT-4o**, **Mistral OCR**, **OpenAI-compatible** (Ollama/LM Studio), or native **PDF text extraction** — all behind a single `SmartOCR::` interface.

> **Note:** The `pdf` driver is a text-extraction engine, not an OCR engine. It reads text already embedded in a digital PDF file. For scanned or image-based PDFs, use Tesseract, Google, AWS, or Azure instead.

---

## Demo

> **Full feature walkthrough** — Simple Text extraction, Batch processing, Multi-language OCR, Document Detection, AI Cleanup, Templates, Workflows, and URL Security.

https://github.com/user-attachments/assets/cf248862-b7cb-4504-8969-4e3745fd0e7c

---

## Table of Contents

- [Quick Start](#quick-start)
- [Free vs Paid Drivers](#free-vs-paid-drivers)
- [Provider Comparison](#provider-comparison)
- [Installation](#installation)
- [Configuration](#configuration)
- [OCR Providers](#ocr-providers)
  - [Tesseract (Free, Offline)](#tesseract-free-offline)
  - [Google Cloud Vision](#google-cloud-vision)
  - [AWS Textract](#aws-textract)
  - [Azure AI Vision](#azure-ai-vision)
  - [Claude Vision](#claude-vision)
  - [OpenAI GPT-4o Vision](#openai-gpt-4o-vision)
  - [PDF Text Extraction](#pdf-text-extraction)
- [The OcrResult Object](#the-ocrresult-object)
- [Schema Extraction](#schema-extraction)
- [Markdown & RAG](#markdown--rag)
- [Smart Routing](#smart-routing)
- [PII Redaction & Classification](#pii-redaction--classification)
- [Human Review](#human-review)
- [Evaluation](#evaluation)
- [Fluent API](#fluent-api)
- [Table Extraction](#table-extraction)
- [Form Fields Extraction](#form-fields-extraction)
- [Queue Support](#queue-support)
- [Error Handling](#error-handling)
- [Templates](#templates)
- [AI Cleanup](#ai-cleanup)
- [Environment Variables Reference](#environment-variables-reference)
- [Privacy](#privacy)
- [Choosing a Driver](#choosing-a-driver)
- [Testing](#testing)
- [Health Check](#health-check)

---

## Quick Start

```bash
composer require laravelsmartocr/laravel-smart-ocr
```

```php
use LaravelSmartOCR\Facades\SmartOCR;

// 60-second quick start — free, no API key needed
$result = SmartOCR::driver('tesseract')->read('/path/to/image.jpg');
echo $result->text();

// Full result object
echo $result->confidence() ?? 'n/a'; // float|null — null when provider does not expose a real score
$result->tables();           // normalized tables
$result->fields();           // key-value form fields
$result->toArray();          // complete structured data
```

Switch providers with zero code changes — just update your `.env`:

```env
SMART_OCR_DRIVER=tesseract   # or google, aws, azure, claude, openai, mistral, pdf
```

---

## Provider Comparison

| Feature | Tesseract | Google | AWS | Azure | Claude | OpenAI | Mistral | Local (Ollama) | PDF |
|---|---|---|---|---|---|---|---|---|---|
| Free | ✓ | — | — | — | — | — | — | ✓ | ✓ |
| Offline | ✓ | — | — | — | — | — | — | ✓ | ✓ |
| Confidence | `float\|null` | `float` | `float` | `float` | `null` | `null` | `null` | `null` | `null` |
| Multi-page PDF | — | ✓ (5p) | ✓ async+S3 | ✓ | — | — | ✓ | — | ✓ |
| Table extraction | basic | — | ✓ structured | — | prompt-based | prompt-based | — | prompt-based | — |
| Bounding boxes | ✓ word | ✓ | ✓ | ✓ | — | — | — | — | — |
| Max file size | unlimited | 20 MB | 5 MB sync | 50 MB | 5 MB | 20 MB | varies | varies | unlimited |
| Languages | 100+ | 100+ | ~12 | 100+ | auto | auto | auto | model-dep | from PDF |

> The `pdf` driver extracts text already embedded in a digital PDF — it is **not** an OCR engine and cannot read scanned or image-based documents.

---

## Installation

```bash
composer require laravelsmartocr/laravel-smart-ocr
```

Publish config:

```bash
php artisan vendor:publish --tag=smart-ocr-config
```

### Optional provider dependencies

Install only the SDK for the provider(s) you use:

```bash
# Google Cloud Vision
composer require google/cloud-vision google/auth

# AWS Textract
composer require aws/aws-sdk-php
```

Azure Vision uses the REST API directly — no extra package needed.

---

## Configuration

`config/smart-ocr.php` (or `.env`):

```env
SMART_OCR_DRIVER=tesseract

# Google
SMART_OCR_GOOGLE_API_KEY=
SMART_OCR_GOOGLE_CREDENTIALS=/path/to/service-account.json
SMART_OCR_GOOGLE_PROJECT_ID=

# AWS
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
SMART_OCR_AWS_TEXTRACT_REGION=us-east-1
SMART_OCR_AWS_BUCKET=my-ocr-bucket

# Azure
SMART_OCR_AZURE_ENDPOINT=https://my-resource.cognitiveservices.azure.com
SMART_OCR_AZURE_KEY=
```

---

## OCR Providers

### Tesseract (Free, Offline)

Local OCR via the Tesseract binary. No API key, no cost, works offline.

> **Confidence:** Tesseract does not expose a reliable per-document confidence score through this driver. `$result->confidence()` returns `null`.

**Install:**
```bash
# Ubuntu/Debian
apt-get install tesseract-ocr

# macOS
brew install tesseract

# Windows — download from https://github.com/UB-Mannheim/tesseract/wiki
```

**Config:**
```env
SMART_OCR_DRIVER=tesseract
```

**Usage:**
```php
$result = SmartOCR::driver('tesseract')->read('path/to/image.png');
echo $result->text();

// Force offline (always uses Tesseract)
$text = SmartOCR::freeText($file);
```

**Supported formats:** jpg, jpeg, png, tiff, bmp

---

### Google Cloud Vision

Uses Google's Document Text Detection API. Excellent for complex layouts, multi-language documents, and printed text.

**Install:**
```bash
composer require google/cloud-vision google/auth
```

**Config:**
```env
SMART_OCR_GOOGLE_API_KEY=your-api-key
# OR service account:
SMART_OCR_GOOGLE_CREDENTIALS=/path/to/service-account.json
SMART_OCR_GOOGLE_PROJECT_ID=my-project
```

**Usage:**
```php
// Image
$result = SmartOCR::driver('google')->read('invoice.jpg');

// With language hint
$result = SmartOCR::driver('google')->language('fr')->read('document.png');

// Uploaded file
$result = SmartOCR::driver('google')->read($request->file('document'));

// PDF (up to 5 pages synchronous)
$result = SmartOCR::driver('google')->read('contract.pdf');

echo $result->text();
echo $result->confidence();  // per-word average
$result->words();            // [{text, confidence, bounding_box}]
$result->pages();            // [{page, text, confidence, lines, blocks}]
```

**Supported formats:** jpg, jpeg, png, gif, bmp, webp, tiff, pdf  
**Max file size:** 20 MB

---

### AWS Textract

Best-in-class for structured documents — invoices, forms, tables. Automatically uses async processing for files over 5 MB.

**Install:**
```bash
composer require aws/aws-sdk-php
```

**Config:**
```env
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
SMART_OCR_AWS_TEXTRACT_REGION=us-east-1
SMART_OCR_AWS_BUCKET=my-bucket    # required for files > 5 MB
```

**Usage:**
```php
// Standard read (auto sync/async)
$result = SmartOCR::driver('aws')->read('invoice.pdf');

// Read directly from S3
$result = SmartOCR::driver('aws')->readFromS3('documents/invoice.pdf');

// Tables
foreach ($result->tables() as $table) {
    $table['headers'];   // ['Item', 'Qty', 'Price']
    $table['rows'];      // [['Item' => 'Widget', 'Qty' => '2', 'Price' => '$10']]
    $table['page'];      // page number
}

// Key-value form fields
foreach ($result->fields() as $field) {
    $field['key'];        // 'Invoice Number'
    $field['value'];      // 'INV-1001'
    $field['confidence']; // 0.99
}
```

**Supported formats:** jpg, jpeg, png, pdf, tiff  
**Sync limit:** 5 MB (auto-upgrades to async + S3 above this)

---

### Azure AI Vision

Uses the Azure Computer Vision Read API (v3.2 / v4.0). Excellent multi-language support and high accuracy on printed documents.

**No extra package needed** — uses the built-in HTTP client.

**Config:**
```env
SMART_OCR_AZURE_ENDPOINT=https://my-resource.cognitiveservices.azure.com
SMART_OCR_AZURE_KEY=your-subscription-key
```

**Usage:**
```php
$result = SmartOCR::driver('azure')->read('document.jpg');

// With language hint
$result = SmartOCR::driver('azure')->language('en')->read('scan.pdf');

echo $result->text();

foreach ($result->lines() as $line) {
    $line['text'];         // 'Invoice Total: $1,200'
    $line['confidence'];   // 0.98
    $line['bounding_box']; // ['x'=>100,'y'=>200,'width'=>300,'height'=>40,'points'=>[...]]
}
```

**Supported formats:** jpg, jpeg, png, bmp, tiff, pdf  
**Max file size:** 50 MB

---

### Claude Vision

Uses Anthropic's Claude models with vision capability. Excellent for complex unstructured documents and natural language understanding.

> **Confidence:** Claude does not return a per-element confidence score. `$result->confidence()` returns `null` for this driver.

**Config:**
```env
SMART_OCR_DRIVER=claude
ANTHROPIC_API_KEY=your-key
```

**Usage:**
```php
$result = SmartOCR::driver('claude')->read('invoice.jpg');
echo $result->text();
echo $result->confidence(); // null — Claude does not expose a confidence score
```

**Supported formats:** jpg, jpeg, png, gif, webp  
**Max file size:** 5 MB

---

### OpenAI GPT-4o Vision

Uses OpenAI's GPT-4o vision capabilities.

> **Confidence:** OpenAI Vision does not return a per-element confidence score. `$result->confidence()` returns `null` for this driver.

**Config:**
```env
SMART_OCR_DRIVER=openai
OPENAI_API_KEY=your-key
```

**Usage:**
```php
$result = SmartOCR::driver('openai')->read('receipt.png');
echo $result->text();
echo $result->confidence(); // null — OpenAI does not expose a confidence score
```

**Supported formats:** jpg, jpeg, png, gif, webp  
**Max file size:** 20 MB

---

### PDF Text Extraction

> **This is not an OCR engine.** It reads the text layer already embedded in a digital PDF using `smalot/pdfparser`. No image recognition is performed, so it cannot process scanned documents or image-based PDFs.

**Usage:**
```php
$result = SmartOCR::driver('pdf')->read('digital-invoice.pdf');
echo $result->text();
echo $result->confidence(); // null — text extraction does not produce a confidence score
```

**When to use:** The document was exported from Word, Excel, or a similar tool (text is selectable in a PDF viewer).  
**When NOT to use:** The document is a scan, a photo, or any PDF where text cannot be selected. Use Tesseract, Google, AWS, or Azure instead.

---

## The OcrResult Object

All providers return the same `OcrResult` object:

```php
$result = SmartOCR::driver('google')->read($file);

// Text
$result->text();          // string — full extracted text
$result->confidence();    // float|null — normalized 0.0–1.0; null when provider does not expose a real score
$result->provider();      // string — 'google', 'aws', 'azure', etc.
$result->isSuccessful();  // bool

// Structure
$result->pages();   // array of pages with text, confidence, lines, blocks
$result->lines();   // array of lines with text, confidence, bounding_box
$result->words();   // array of words with text, confidence, bounding_box
$result->blocks();  // array of blocks (Google-style paragraph groups)
$result->tables();  // array of tables with headers and rows
$result->fields();  // array of key-value pairs (AWS Textract forms)

// Raw & meta
$result->raw();       // original provider response, unmodified
$result->metadata();  // engine, language, processing info
$result->errors();    // any non-fatal errors

// Full structured array
$result->toArray();
```

### toArray() schema

```php
[
    'success'    => true,
    'provider'   => 'google',
    'text'       => 'Invoice\nINV-1001\nTotal: $1,200.00',
    'confidence' => 0.98,   // float|null — null for Tesseract, Claude, OpenAI, PDF
    'pages'      => [
        [
            'page'       => 1,
            'text'       => '...',
            'confidence' => 0.98,
            'lines'      => [...],
            'blocks'     => [...],
        ],
    ],
    'lines'      => [
        ['text' => 'Invoice', 'confidence' => 0.99, 'bounding_box' => ['x'=>100,'y'=>100,'width'=>200,'height'=>30,'points'=>[...]]],
    ],
    'words'      => [...],
    'blocks'     => [...],
    'tables'     => [...],
    'fields'     => [...],
    'metadata'   => ['engine' => 'google-vision', 'language' => 'auto'],
    'raw'        => [...],   // original API response
    'errors'     => [],
]
```

### Bounding box format

Every word, line, and block has a normalized bounding box:

```php
[
    'x'      => 100,    // left edge in pixels
    'y'      => 200,    // top edge in pixels
    'width'  => 300,    // width in pixels
    'height' => 40,     // height in pixels
    'points' => [       // four corner points
        ['x' => 100, 'y' => 200],
        ['x' => 400, 'y' => 200],
        ['x' => 400, 'y' => 240],
        ['x' => 100, 'y' => 240],
    ],
]
```

---

## Schema Extraction

Extract structured data directly into a typed PHP class:

```php
use LaravelSmartOCR\Extraction\Attributes\Field;
use LaravelSmartOCR\Extraction\Attributes\ListOf;

final class Invoice {
    public function __construct(
        #[Field('Invoice number')] public string $number = '',
        #[Field('Grand total, number only')] public float $total = 0.0,
        #[Field('Due date, ISO format')] public ?string $dueDate = null,
    ) {}
}

$result = SmartOCR::from($file)->extract(Invoice::class);
// $result->data is a typed Invoice object
echo $result->data->number;  // "INV-2026-001"
echo $result->data->total;   // 1250.0

// Per-field citations
$field = $result->field('total');
echo $field->confidence;     // float or null
echo $field->citation->page; // 1
```

Works free with the `rules` engine (regex/keyword). Set `SMART_OCR_EXTRACTION_ENGINE=llm` to use an LLM.

---

## Markdown & RAG

```php
$result = SmartOCR::from($file)->read();

// Render as Markdown (tables, headings preserved)
echo $result->toMarkdown();

// Split into chunks for vector stores
foreach ($result->chunks(maxTokens: 500) as $chunk) {
    echo $chunk->text;       // chunk text
    echo $chunk->heading;    // section heading
    echo $chunk->startPage;  // page number
}
```

---

## Smart Routing

```php
// Cheapest available driver first
SmartOCR::from($file)->cheapest()->read();

// Highest quality first
SmartOCR::from($file)->best()->read();

// Escalate if confidence is low
SmartOCR::from($file)
    ->using('tesseract')
    ->escalateTo('mistral', whenConfidenceBelow: 0.80)
    ->read();

// Cache result for 30 days (keyed by file hash + driver)
SmartOCR::from($file)->cache(ttl: now()->addDays(30))->read();
```

---

## PII Redaction & Classification

```php
$result = SmartOCR::from($file)->read();

// Redact PII from text
$clean = $result->redact(['email', 'phone', 'card'])->text();

// Classify document type
$type = $result->classify(); // ['type' => 'invoice', 'confidence' => 0.75]
```

---

## Human Review

Publish the migration and run it:
```bash
php artisan vendor:publish --tag=smart-ocr-migrations
php artisan migrate
```

Then flag low-confidence fields for review:
```php
$result = SmartOCR::from($file)->extract(Invoice::class);

// Fields below 0.85 confidence go to the review queue
$reviews = $result->sendToReview(threshold: 0.85, documentHash: hash_file('sha256', $file));

// Later, approve or correct
$review->approve();
$review->correct('INV-2026-001');
echo $review->final(); // corrected value
```

---

## Evaluation

Test driver accuracy against expected outputs:
```bash
# Put files + expected JSON (same name, .json extension) in a directory
php artisan smart-ocr:eval tests/fixtures/docs --drivers=tesseract,mistral
php artisan smart-ocr:eval tests/fixtures/docs --drivers=tesseract --json=report.json
```

---

## Free vs Paid Drivers

| Driver | Cost | Needs API key | Offline |
|---|---|---|---|
| `tesseract` | Free | No | ✓ |
| `pdf` | Free | No | ✓ |
| `openai_compatible` | Free (local model) | No | ✓ with Ollama |
| `google` | Pay per page | Yes | — |
| `aws` | Pay per page | Yes | — |
| `azure` | Pay per page | Yes | — |
| `mistral` | Pay per page | Yes | — |
| `claude` | Pay per token | Yes | — |
| `openai` | Pay per token | Yes | — |

The default driver is `tesseract`. New users get results immediately, with no account or payment.

---

## Fluent API

The `driver()` method returns a fluent builder:

```php
// Language hint
$result = SmartOCR::driver('google')
    ->language('fr')
    ->read($file);

// Fallback to Tesseract if cloud fails
$result = SmartOCR::driver('azure')
    ->fallback('tesseract')
    ->read($file);

// Chain with fallback
$result = SmartOCR::driver('google')
    ->language('en')
    ->fallback('tesseract')
    ->read($request->file('invoice'));
```

---

## Table Extraction

```php
$result = SmartOCR::driver('aws')->read('invoice.pdf');

foreach ($result->tables() as $table) {
    echo "Page: " . $table['page'] . "\n";
    echo "Headers: " . implode(', ', $table['headers']) . "\n";

    foreach ($table['rows'] as $row) {
        echo $row['Item'] . ' — ' . $row['Price'] . "\n";
    }
}
```

Output structure:
```php
[
    [
        'page'    => 1,
        'headers' => ['Item', 'Quantity', 'Price', 'Total'],
        'rows'    => [
            ['Item' => 'Widget A', 'Quantity' => '2', 'Price' => '$10.00', 'Total' => '$20.00'],
        ],
    ],
]
```

> Table extraction is supported by **AWS Textract** (structured) and basic parsing for Tesseract/PDF. Google and Azure return `[]`.

---

## Form Fields Extraction

AWS Textract automatically detects key-value pairs in forms:

```php
$result = SmartOCR::driver('aws')->read('form.pdf');

foreach ($result->fields() as $field) {
    echo $field['key'] . ': ' . $field['value'];
    echo ' (' . round($field['confidence'] * 100) . "% confidence)\n";
}
// Invoice Number: INV-1001 (99% confidence)
// Date: 2026-09-23 (98% confidence)
// Total: $1,200.00 (99% confidence)
```

---

## Queue Support

Dispatch OCR jobs to the Laravel queue:

```php
use LaravelSmartOCR\Facades\SmartOCR;
use LaravelSmartOCR\Events\OcrCompleted;
use LaravelSmartOCR\Events\OcrFailed;

// Dispatch
SmartOCR::queue('/path/to/invoice.pdf', 'google');
SmartOCR::queue('/path/to/invoice.pdf', 'aws', ['language' => 'en']);

// Listen for results
Event::listen(OcrCompleted::class, function (OcrCompleted $event) {
    $result = $event->result;
    $driver = $event->driver;

    Log::info("OCR complete via {$driver}: " . substr($result->text(), 0, 100));
});

Event::listen(OcrFailed::class, function (OcrFailed $event) {
    Log::error("OCR failed via {$event->driver}: " . $event->exception->getMessage());
});
```

The job class `LaravelSmartOCR\Jobs\ProcessOcrJob` implements `ShouldQueue` with 3 retries and 300s timeout.

---

## Error Handling

All provider errors are wrapped in package-level exceptions:

```php
use LaravelSmartOCR\Exceptions\OCRException;
use LaravelSmartOCR\Exceptions\ConfigurationException;
use LaravelSmartOCR\Exceptions\AuthenticationException;
use LaravelSmartOCR\Exceptions\RateLimitException;
use LaravelSmartOCR\Exceptions\OcrTimeoutException;
use LaravelSmartOCR\Exceptions\UnsupportedDocumentException;
use LaravelSmartOCR\Exceptions\ProviderException;

try {
    $result = SmartOCR::driver('google')->read($file);
} catch (AuthenticationException $e) {
    // Invalid API key / credentials
} catch (RateLimitException $e) {
    $retryAfter = $e->retryAfter(); // seconds
    // Queue for retry
} catch (OcrTimeoutException $e) {
    // Request timed out
} catch (UnsupportedDocumentException $e) {
    // File format not supported by this provider
} catch (ConfigurationException $e) {
    // Missing required config key
} catch (OCRException $e) {
    // Catch-all for any OCR error
}
```

### Retry behavior

Cloud drivers automatically retry on:
- Rate limit errors (respects `Retry-After`)
- Timeout errors (exponential backoff)
- Transient network errors

They do **not** retry on:
- Authentication errors
- Unsupported document format
- Invalid documents

---

## Templates

Extract structured fields from document templates:

```php
// Apply a saved template
$result = SmartOCR::extractWithTemplate($file, $templateId);

// Auto-detect template from content
$parser = app('smart-ocr.parser');
$result = $parser->parse($file, ['detect_template' => true]);
```

---

## AI Cleanup

Post-process extracted text with an AI model to correct OCR errors:

```php
$parser = app('smart-ocr.parser');
$result = $parser->parse($file, [
    'ai_cleanup' => true,
    'document_type' => 'invoice',
]);
```

---

## Environment Variables Reference

```env
# Default driver
SMART_OCR_DRIVER=tesseract

# Tesseract
TESSERACT_BINARY=/usr/bin/tesseract
SMART_OCR_TESSERACT_LANGUAGE=eng

# Google Cloud Vision
SMART_OCR_GOOGLE_API_KEY=
SMART_OCR_GOOGLE_CREDENTIALS=/path/to/service-account.json
SMART_OCR_GOOGLE_PROJECT_ID=
SMART_OCR_GOOGLE_LOCATION=us
SMART_OCR_GOOGLE_TIMEOUT=60

# AWS Textract
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
SMART_OCR_AWS_TEXTRACT_REGION=us-east-1
SMART_OCR_AWS_BUCKET=
SMART_OCR_AWS_TIMEOUT=120

# Azure AI Vision
SMART_OCR_AZURE_ENDPOINT=
SMART_OCR_AZURE_KEY=
SMART_OCR_AZURE_API_VERSION=2023-02-01-preview
SMART_OCR_AZURE_TIMEOUT=60

# Claude Vision
ANTHROPIC_API_KEY=
SMART_OCR_CLAUDE_MODEL=claude-opus-4-7

# OpenAI Vision
OPENAI_API_KEY=
SMART_OCR_OPENAI_MODEL=gpt-4o
```

---

## Privacy

Cloud and LLM drivers (Google, AWS, Azure, Claude, OpenAI) send your document contents to third-party APIs. For sensitive documents, use the **Tesseract** or **PDF** drivers, which process everything locally.

Configure which providers are allowed:

```php
// config/smart-ocr.php
'privacy' => [
    'allow_external_ai' => false, // block cloud/LLM drivers
],
```

---

## Choosing a Driver

| Use case | Recommended driver |
|---|---|
| Forms, tables, structured data | `aws` (Textract) |
| Multilingual printed text | `google` or `azure` |
| Offline / private documents | `tesseract` or `pdf` |
| Messy layouts, handwriting | `claude` or `openai`* |
| PDF with embedded text | `pdf` |

*LLM drivers can silently correct, reorder, or invent text. Do not rely on them for legally-required verbatim accuracy without human review.

---

## Testing

Use `SmartOCR::fake()` to swap in a fake driver during tests:

```php
use LaravelSmartOCR\Testing\SmartOCRFake;
use LaravelSmartOCR\Results\OcrResult;

$fake = SmartOCR::fake();
$fake->addResult(OcrResult::fromArray([
    'text'     => 'Invoice #1234',
    'provider' => 'google',
]));

$result = SmartOCR::driver('google')->read('/path/to/invoice.pdf');
$this->assertEquals('Invoice #1234', $result->text());

$fake->assertRead('/path/to/invoice.pdf');
```

---

## Health Check

```bash
php artisan smart-ocr:doctor
```

Reports: Tesseract binary and version, installed language packs, Ghostscript, and cloud driver credential status.

---

## License

MIT — see [LICENSE](LICENSE)
