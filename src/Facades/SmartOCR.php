<?php declare(strict_types=1);
namespace LaravelSmartOCR\Facades;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use LaravelSmartOCR\Results\OcrResult;
use LaravelSmartOCR\Services\OcrDriverBuilder;
use LaravelSmartOCR\Testing\SmartOCRFake;

/**
 * @method static OcrDriverBuilder driver(string $driver = null)
 * @method static OcrDriverBuilder from(mixed $source)
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
     * Swap in a fake OCR manager for testing.
     *
     * Usage:
     *   $fake = SmartOCR::fake();
     *   SmartOCR::read('/path/to/file.pdf');
     *   $fake->assertRead('/path/to/file.pdf');
     *
     *   // With a preset result:
     *   $fake = SmartOCR::fake(['text' => 'Invoice #1234']);
     *   $fake = SmartOCR::fake(OcrResult::fromArray(['text' => '...', 'provider' => 'google']));
     */
    public static function fake(array|OcrResult|null $result = null): SmartOCRFake
    {
        $fake = new SmartOCRFake($result);
        // Bind fake into the container so OCRManager uses it
        static::getFacadeApplication()->instance('smart-ocr.fake', $fake);
        static::getFacadeApplication()->bind(
            \LaravelSmartOCR\Services\OCRManager::class,
            fn ($app) => new \LaravelSmartOCR\Testing\FakeOCRManager($fake, $app)
        );
        // Also rebind the facade accessor so SmartOCR::read() uses the fake
        static::getFacadeApplication()->bind('smart-ocr', fn ($app) => new \LaravelSmartOCR\Testing\FakeOCRManager($fake, $app));
        static::clearResolvedInstance('smart-ocr');
        return $fake;
    }
}
