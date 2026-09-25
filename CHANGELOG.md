# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-09-25

### Added
- `SmartOCR::from()` fluent API with `DocumentSource` (path, UploadedFile, disk path, bytes)
- Schema extraction: `#[Field]`, `#[ListOf]` attributes, `SchemaBuilder`, `RulesEngine` (free), `LlmEngine`
- `ExtractionResult` with `field()`, `needsReview()`, `isConfident()`, `sendToReview()`
- `FieldResult` with per-field confidence (never invented) and `FieldCitation` (page + bbox)
- `OcrResult::toMarkdown()` and `OcrResult::chunks()` for RAG pipelines
- `OcrResult::redact()` — PII redaction (email, phone, card, IBAN, custom regex)
- `OcrResult::classify()` — rule-based document classification
- `OcrResult::cost()` — cost estimate DTO
- `SmartOCR::from()->cheapest()`, `->best()`, `->escalateTo()`, `->cache()` smart routing
- `MistralOcrDriver` — Mistral OCR API (`mistral-ocr-latest`)
- `OpenAiCompatibleDriver` — generic driver for Ollama, LM Studio, or any OpenAI-compatible endpoint
- `OcrReview` model + migration for human review queue
- `ReviewRequested` and `ReviewCompleted` events
- `HasOcrDocuments` Eloquent trait
- `ExtractionNeedsReview` event
- `smart-ocr:eval` command — per-driver accuracy, confidence, cost report
- `BudgetExceededException` — thrown when cost estimate exceeds `routing.max_cost_per_document`
- `OcrRouter` — driver selection by cost or quality order
- Pricing config (`smart-ocr.pricing.*`) — all prices configurable, never hardcoded

### Changed
- `SmartOCR::fake()` now supports `sequence()`, `assertReadWith()`, `assertUsedDriver()`, `preventStrayRequests()`
- `OcrDriverBuilder::async()` triggers `E_USER_DEPRECATED` (was silent no-op)
- MIME type detection now uses `finfo` instead of file extension (fixes UploadedFile handling)
- `TesseractDriver` no longer deletes files it didn't create
- Hardcoded confidence values (`0.95`, `1.0`, `0.0`) removed — all confidence is now real or `null`
- Retry logic limited to HTTP 429, 5xx, and network errors (was retrying 4xx)

### Deprecated
- `OcrDriverBuilder::async()` — remove from your code
- `DocumentParser::parse()` option `ai_cleanup` → use `use_ai_cleanup`
- `DocumentParser::parse()` option `detect_template` → use `auto_detect_template`

## [2.1.0] - 2026-09-23

### Added
- `OcrResult` unified value object returned by all drivers
- Google Cloud Vision, AWS Textract, Azure Computer Vision (Read 3.2 GA) drivers
- `SmartOCR::fake()` testing helper with `assertRead()` / `assertNothingRead()`
- `OcrFallbackUsed` event with metadata (requested driver, actual driver, reason)
- `smart-ocr:doctor` artisan health-check command
- `BoundingBoxNormalizer` — consistent box format across all drivers
- `Retryable` trait — exponential backoff with `RateLimitException` support
- `schema_version => 1` in `OcrResult::toArray()`

### Changed
- `OcrResult::confidence()` returns `null` for tesseract, claude, openai, pdf (these providers do not expose a real confidence score)
- AWS Textract sync/async decision now checks page count AND file size (multi-page PDFs always use async)
- Google API key sent via `x-goog-api-key` header instead of query string
- Azure driver uses Read 3.2 GA endpoint (was Image Analysis 4.0 preview)
- `ProcessOcrJob` no longer retries on `AuthenticationException`, `ConfigurationException`, or `UnsupportedDocumentException`
- `async()` on `OcrDriverBuilder` is deprecated (was always a no-op)

### Removed
- `composer.lock` (libraries should not commit lock files)
- `minimum-stability` and `prefer-stable` from `composer.json` (root-only settings)
- Dev/test artefacts (`package-evaluation.md`, `advanced-invoice-extractor.php`, temp files)

## [2.0.1] - 2026-09-25

### Fixed
- `OcrResult::confidence()` now returns `null` for providers that do not expose a genuine
  confidence score (Tesseract, Claude, OpenAI, PDF). Previously returned a static `0.95`.

### Changed
- README provider comparison table corrected.

## [2.0.0] - 2026-09-23

### Added
- `GoogleVisionDriver` — Google Cloud Vision API (images + PDFs up to 5 pages).
- `AwsTextractDriver` — AWS Textract with sync/async, table and form-field extraction.
- `AzureVisionDriver` — Azure Computer Vision Read API.
- `OcrResult` value object — unified result across all drivers.
- `OcrDriverBuilder` — fluent API: `.language()`, `.fallback()`, `.read()`.
- `BoundingBoxNormalizer` — normalized bounding boxes across all providers.
- `Retryable` trait — exponential back-off for cloud drivers.
- `ProcessOcrJob` + `OcrCompleted` / `OcrFailed` events.
- Six new exception classes.

### Changed
- `OCRManager::driver()` now returns `OcrDriverBuilder` (backward-compatible via `__call`).

## [1.0.3] - 2026-09-13

### Added
- Laravel 13 support.

## [1.0.0] - 2025-08-14

### Added
- Initial release with Tesseract, Claude Vision, OpenAI Vision, and PDF text-extraction drivers.
