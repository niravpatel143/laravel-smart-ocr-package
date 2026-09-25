# Upgrade Guide

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
