<?php declare(strict_types=1);
namespace LaravelSmartOCR\Concerns;

use Illuminate\Http\UploadedFile;
use LaravelSmartOCR\Extraction\ExtractionResult;
use LaravelSmartOCR\Facades\SmartOCR;
use LaravelSmartOCR\Results\OcrResult;

/**
 * Eloquent trait for models that own OCR documents.
 *
 * Usage:
 *   class Order extends Model {
 *       use HasOcrDocuments;
 *   }
 *
 *   $order->attachDocument($file)->extract(Invoice::class);
 *   $order->ocrRead('/path/to/doc.pdf', 'aws');
 */
trait HasOcrDocuments
{
    private mixed $_pendingOcrDocument = null;
    private string $_pendingOcrDriver  = '';

    public function attachDocument(mixed $file, string $driver = ''): static
    {
        $this->_pendingOcrDocument = $file;
        $this->_pendingOcrDriver   = $driver ?: config('smart-ocr.default', 'tesseract');
        return $this;
    }

    public function ocrRead(mixed $file = null, string $driver = ''): OcrResult
    {
        $doc    = $file ?? $this->_pendingOcrDocument;
        $drv    = $driver ?: $this->_pendingOcrDriver ?: config('smart-ocr.default', 'tesseract');
        $result = SmartOCR::driver($drv)->read($doc);
        $this->_pendingOcrDocument = null;
        return $result;
    }

    public function extract(string|array $schema, mixed $file = null, string $driver = ''): ExtractionResult
    {
        $doc    = $file ?? $this->_pendingOcrDocument;
        $drv    = $driver ?: $this->_pendingOcrDriver ?: config('smart-ocr.default', 'tesseract');
        $result = SmartOCR::driver($drv)->read($doc);
        $this->_pendingOcrDocument = null;
        return (new \LaravelSmartOCR\Extraction\ExtractionService())->extract($result, $schema);
    }
}
