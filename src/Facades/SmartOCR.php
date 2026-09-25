<?php declare(strict_types=1);
namespace LaravelSmartOCR\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Services\OcrDriverBuilder;
use LaravelSmartOCR\Testing\SmartOCRFake;

/**
 * @method static OcrDriverBuilder driver(string $driver = null)
 * @method static OcrResult read(mixed $document, array $options = [])
 * @method static string getText(mixed $document, string $language = 'eng')
 * @method static string freeText(mixed $document, string $language = 'eng')
 * @method static array extract(mixed $document, array $options = [])
 * @method static array extractTable(mixed $document, array $options = [])
 * @method static array extractBarcode(mixed $document, array $options = [])
 * @method static array extractQRCode(mixed $document, array $options = [])
 * @method static \Illuminate\Foundation\Bus\PendingDispatch queue(string $filePath, string $driver = null, array $options = [])
 * @method static void extend(string $driver, \Closure $callback)
 *
 * @see \LaravelSmartOCR\Services\OCRManager
 */
class SmartOCR extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'smart-ocr';
    }

    /**
     * Swap in a fake OCR driver for testing.
     *
     * Usage:
     *   $fake = SmartOCR::fake();
     *   SmartOCR::driver('fake')->read($file);
     *   $fake->assertRead('/path/to/file.pdf');
     */
    public static function fake(): SmartOCRFake
    {
        $manager = static::getFacadeRoot();
        $fake    = new SmartOCRFake($manager);
        return $fake;
    }
}
