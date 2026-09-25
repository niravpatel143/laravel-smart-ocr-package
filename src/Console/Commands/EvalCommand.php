<?php declare(strict_types=1);
namespace LaravelSmartOCR\Console\Commands;

use Illuminate\Console\Command;
use LaravelSmartOCR\Extraction\ExtractionService;
use LaravelSmartOCR\Facades\SmartOCR;

class EvalCommand extends Command
{
    protected $signature = 'smart-ocr:eval
        {path : Directory containing documents and expected JSON files}
        {--drivers= : Comma-separated list of drivers to evaluate (default: default driver)}
        {--json= : Save report to this JSON file path}';

    protected $description = 'Evaluate OCR driver accuracy against expected outputs';

    public function handle(): int
    {
        $path    = $this->argument('path');
        $drivers = $this->option('drivers')
            ? explode(',', $this->option('drivers'))
            : [config('smart-ocr.default', 'tesseract')];

        if (!is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return self::FAILURE;
        }

        $files = glob("{$path}/*.{jpg,jpeg,png,pdf,tiff}", GLOB_BRACE) ?: [];
        if (empty($files)) {
            $this->warn("No document files found in {$path}");
            return self::SUCCESS;
        }

        $report = [];

        foreach ($drivers as $driver) {
            $driver  = trim($driver);
            $this->info("Evaluating driver: {$driver}");
            $results = [];

            foreach ($files as $file) {
                $expected = $this->loadExpected($file);
                $start    = microtime(true);

                try {
                    $ocrResult = SmartOCR::driver($driver)->read($file);
                    $durationMs = (int)((microtime(true) - $start) * 1000);

                    if ($expected !== null) {
                        $service = new ExtractionService();
                        $schema  = array_combine(array_keys($expected), array_fill(0, count($expected), 'string'));
                        $ext     = $service->extract($ocrResult, $schema);
                        $accuracy = $this->computeAccuracy($expected, $ext->raw);
                    } else {
                        $accuracy = null;
                    }

                    $results[] = [
                        'file'        => basename($file),
                        'status'      => 'ok',
                        'duration_ms' => $durationMs,
                        'confidence'  => $ocrResult->confidence(),
                        'accuracy'    => $accuracy,
                        'cost'        => $ocrResult->cost()?->toArray(),
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'file'   => basename($file),
                        'status' => 'error',
                        'error'  => $e->getMessage(),
                    ];
                }
            }

            $report[$driver] = $results;
            $this->printDriverReport($driver, $results);
        }

        if ($jsonPath = $this->option('json')) {
            file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT));
            $this->info("Report saved to {$jsonPath}");
        }

        return self::SUCCESS;
    }

    private function loadExpected(string $file): ?array
    {
        $expectedFile = preg_replace('/\.[^.]+$/', '.json', $file);
        if ($expectedFile && file_exists($expectedFile)) {
            $data = json_decode((string)file_get_contents($expectedFile), true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    private function computeAccuracy(array $expected, array $actual): float
    {
        if (empty($expected)) return 1.0;
        $matches = 0;
        foreach ($expected as $key => $expectedVal) {
            $actualVal = $actual[$key] ?? null;
            if ($actualVal !== null && strtolower(trim((string)$actualVal)) === strtolower(trim((string)$expectedVal))) {
                $matches++;
            }
        }
        return round($matches / count($expected), 3);
    }

    private function printDriverReport(string $driver, array $results): void
    {
        $ok       = array_filter($results, fn($r) => $r['status'] === 'ok');
        $errors   = count($results) - count($ok);
        $avgConf  = count($ok) ? round(array_sum(array_column($ok, 'confidence')) / count($ok), 3) : null;
        $avgAcc   = array_filter(array_column($ok, 'accuracy'), fn($a) => $a !== null);
        $avgAccStr = $avgAcc ? round(array_sum($avgAcc) / count($avgAcc) * 100, 1) . '%' : 'n/a';
        $avgMs    = count($ok) ? (int)(array_sum(array_column($ok, 'duration_ms')) / count($ok)) : 0;

        $rows = array_map(fn($r) => [
            $r['file'],
            $r['status'],
            isset($r['duration_ms']) ? $r['duration_ms'] . 'ms' : '-',
            isset($r['confidence']) ? ($r['confidence'] !== null ? number_format((float)$r['confidence'], 3) : 'null') : '-',
            isset($r['accuracy']) ? ($r['accuracy'] !== null ? round($r['accuracy'] * 100, 1) . '%' : 'n/a') : '-',
            $r['error'] ?? '-',
        ], $results);

        $this->table(['File', 'Status', 'Duration', 'Confidence', 'Accuracy', 'Error'], $rows);
        $this->line("  Summary: " . count($ok) . " ok, {$errors} errors | avg confidence: {$avgConf} | avg accuracy: {$avgAccStr} | avg time: {$avgMs}ms");
    }
}
