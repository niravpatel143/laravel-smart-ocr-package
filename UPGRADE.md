# Upgrade Guide

## Upgrading from 2.x to 3.0

### Breaking changes

None. All existing `SmartOCR::driver()->read()` calls continue to work.

### Deprecated in 3.0

- `OcrDriverBuilder::async()` — triggers `E_USER_DEPRECATED`. Remove it from your code.
- `DocumentParser::parse()` option `ai_cleanup` — use `use_ai_cleanup` instead.
- `DocumentParser::parse()` option `detect_template` — use `auto_detect_template` instead.

### New migrations (optional)

To use the human review queue, publish and run the migration:
```bash
php artisan vendor:publish --tag=smart-ocr-migrations
php artisan migrate
```

### New config keys

Run `vendor:publish --tag=smart-ocr-config --force` to get the new config keys, or add manually:
- `extraction.engine` — `'rules'` (default, free) or `'llm'`
- `pricing.*` — per-page cost estimates for each driver
- `routing.quality_order` — driver preference order for `->best()`
- `routing.max_cost_per_document` — budget guard (0 = disabled)
- `drivers.mistral.*` — Mistral OCR config
- `drivers.openai_compatible.*` — local/self-hosted model config

## v2.0.x → v2.1.0 (upcoming)

### `OcrDriverBuilder::async()` deprecated

`->async()` was documented as a no-op and is now formally deprecated. It will be removed in v3.0.
Replace with no call at all — async processing is handled automatically by each driver.

```php
// Before (v2.0)
SmartOCR::driver('aws')->async()->read($file);

// After (v2.1+)
SmartOCR::driver('aws')->read($file);
```

## v1.x → v2.0.0

### `OcrResult::confidence()` returns `?float`

`confidence()` now returns `null` for drivers that do not expose a genuine per-element
confidence score: `tesseract`, `claude`, `openai`, and `pdf`.

```php
// Before — always returned a float (0.95 was a static placeholder)
echo $result->confidence(); // 0.95

// After — returns null for non-confidence providers
echo $result->confidence() ?? 'n/a'; // n/a
```

### `driver()` returns `OcrDriverBuilder`

`SmartOCR::driver('x')` now returns an `OcrDriverBuilder` instead of a raw `OCRDriver`.
All `OCRDriver` methods are still callable via `__call`, so existing code is unaffected.

### New result schema

`OcrResult::toArray()` now includes `schema_version => 1`. Add this key to any code
that validates the shape of the array.
