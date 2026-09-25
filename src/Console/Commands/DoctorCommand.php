<?php declare(strict_types=1);

namespace LaravelSmartOCR\Console\Commands;

use Illuminate\Console\Command;
use LaravelSmartOCR\Drivers\TesseractDriver;
use LaravelSmartOCR\Results\OcrResult;

class DoctorCommand extends Command
{
    protected $signature   = 'smart-ocr:doctor {--test : Run a tiny built-in test through each "ready" driver}';
    protected $description = 'Check the Smart OCR environment: PHP extensions, binaries, credentials, and optional packages';

    private bool $hasError = false;

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold> Smart OCR — Doctor</>');
        $this->line(str_repeat('─', 60));

        $this->checkPhpAndExtensions();
        $this->checkTesseract();
        $this->checkGhostscript();
        $this->checkDriverCredentials();
        $this->checkOptionalPackages();
        $this->checkMigrations();

        if ($this->option('test')) {
            $this->runDriverTests();
        }

        $this->line('');
        if ($this->hasError) {
            $this->line('<error> One or more required checks failed. </error>');
            return 1;
        }

        $this->line('<info> All required checks passed. </info>');
        return 0;
    }

    // ── PHP + Extensions ──────────────────────────────────────────────────

    private function checkPhpAndExtensions(): void
    {
        $this->line('');
        $this->line('<options=bold>PHP & Extensions</>');

        $this->line('  <fg=green>✓</> PHP version: ' . PHP_VERSION);

        $required = ['curl', 'fileinfo', 'json'];
        $optional = ['gd', 'imagick'];

        $rows = [];

        foreach ($required as $ext) {
            if (extension_loaded($ext)) {
                $rows[] = ["ext-{$ext}", '<fg=green>✓ loaded</>', ''];
            } else {
                $rows[] = ["ext-{$ext}", '<error>✗ missing</>', "apt install php-{$ext}"];
                $this->hasError = true;
            }
        }

        foreach ($optional as $ext) {
            if (extension_loaded($ext)) {
                $rows[] = ["ext-{$ext}", '<fg=green>✓ loaded</>', ''];
            } else {
                $rows[] = ["ext-{$ext}", '<comment>⚠ optional — not loaded</>', "apt install php-{$ext}"];
            }
        }

        $this->table(['Extension', 'Status', 'Fix'], $rows);
    }

    // ── Tesseract ─────────────────────────────────────────────────────────

    private function checkTesseract(): void
    {
        $this->line('');
        $this->line('<options=bold>Tesseract OCR</>');

        $version = $this->runBinaryCheck('tesseract', ['--version']);
        if ($version === null) {
            $this->line('  <comment>⚠ not found — apt install tesseract-ocr tesseract-ocr-eng</>');
            return;
        }

        $firstLine = strtok($version, "\n");
        $this->line("  <fg=green>✓</> Version: {$firstLine}");

        $langs = $this->runBinaryCheck('tesseract', ['--list-langs']);
        if ($langs !== null) {
            $langList = array_filter(array_slice(explode("\n", $langs), 1)); // skip header
            $this->line('  <fg=green>✓</> Language packs: ' . implode(', ', array_slice(array_values($langList), 0, 10))
                . (count($langList) > 10 ? ' (+' . (count($langList) - 10) . ' more)' : ''));
        }
    }

    // ── Ghostscript ───────────────────────────────────────────────────────

    private function checkGhostscript(): void
    {
        $this->line('');
        $this->line('<options=bold>Ghostscript (PDF → image)</>');

        foreach (['gs', 'gswin64c', 'gswin32c'] as $bin) {
            $version = $this->runBinaryCheck($bin, ['--version']);
            if ($version !== null) {
                $this->line("  <fg=green>✓</> {$bin} v" . trim($version));
                return;
            }
        }

        $this->line('  <comment>⚠ not found — apt install ghostscript (needed only for PDF → Tesseract)</comment>');
    }

    // ── Per-driver credential check ───────────────────────────────────────

    private function checkDriverCredentials(): void
    {
        $this->line('');
        $this->line('<options=bold>Driver Credentials</>');

        $rows = [];

        // google
        $googleKey  = config('smart-ocr.drivers.google.api_key', '');
        $googleCred = config('smart-ocr.drivers.google.credentials', '');
        $googleOk   = !empty($googleKey) || (!empty($googleCred) && file_exists((string) $googleCred));
        $rows[] = [
            'google',
            $googleOk ? '<fg=green>✓ present</>' : '<comment>⚠ missing</>',
            $googleOk ? '' : 'Set SMART_OCR_GOOGLE_API_KEY or SMART_OCR_GOOGLE_CREDENTIALS',
        ];

        // aws
        $awsKey    = config('smart-ocr.drivers.aws.key', '');
        $awsSecret = config('smart-ocr.drivers.aws.secret', '');
        $awsOk     = !empty($awsKey) && !empty($awsSecret);
        $rows[] = [
            'aws',
            $awsOk ? '<fg=green>✓ present</>' : '<comment>⚠ missing</>',
            $awsOk ? '' : 'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY',
        ];

        // azure
        $azureKey      = config('smart-ocr.drivers.azure.key', '');
        $azureEndpoint = config('smart-ocr.drivers.azure.endpoint', '');
        $azureOk       = !empty($azureKey) && !empty($azureEndpoint);
        $rows[] = [
            'azure',
            $azureOk ? '<fg=green>✓ present</>' : '<comment>⚠ missing</>',
            $azureOk ? '' : 'Set SMART_OCR_AZURE_KEY and SMART_OCR_AZURE_ENDPOINT',
        ];

        // claude
        $claudeKey = config('smart-ocr.drivers.claude.api_key', '');
        $claudeOk  = !empty($claudeKey);
        $rows[] = [
            'claude',
            $claudeOk ? '<fg=green>✓ present</>' : '<comment>⚠ missing</>',
            $claudeOk ? '' : 'Set ANTHROPIC_API_KEY',
        ];

        // openai
        $openaiKey = config('smart-ocr.drivers.openai.api_key', '');
        $openaiOk  = !empty($openaiKey);
        $rows[] = [
            'openai',
            $openaiOk ? '<fg=green>✓ present</>' : '<comment>⚠ missing</>',
            $openaiOk ? '' : 'Set OPENAI_API_KEY',
        ];

        $this->table(['Driver', 'Status', 'Fix'], $rows);
    }

    // ── Optional packages ─────────────────────────────────────────────────

    private function checkOptionalPackages(): void
    {
        $this->line('');
        $this->line('<options=bold>Optional Packages</>');

        $packages = [
            ['class' => \Aws\Textract\TextractClient::class,   'package' => 'aws/aws-sdk-php'],
            ['class' => \GuzzleHttp\Client::class,             'package' => 'guzzlehttp/guzzle'],
            ['class' => \OpenAI\Client::class,                 'package' => 'openai-php/client'],
        ];

        $rows = [];
        foreach ($packages as $pkg) {
            $loaded = class_exists($pkg['class']);
            $rows[] = [
                $pkg['package'],
                $loaded ? '<fg=green>✓ installed</>' : '<comment>⚠ not installed</>',
                $loaded ? '' : "composer require {$pkg['package']}",
            ];
        }

        $this->table(['Package', 'Status', 'Install'], $rows);
    }

    // ── Migrations check ──────────────────────────────────────────────────

    private function checkMigrations(): void
    {
        $this->line('');
        $this->line('<options=bold>Migrations</>');

        try {
            $exists = \Illuminate\Support\Facades\Schema::hasTable('ocr_reviews');
            if ($exists) {
                $this->line('  <fg=green>✓</> ocr_reviews table exists');
            } else {
                $this->line('  <comment>⚠ ocr_reviews table not found — run: php artisan vendor:publish --tag=smart-ocr-migrations && php artisan migrate</comment>');
            }
        } catch (\Throwable) {
            $this->line('  <comment>⚠ Could not check migrations (no DB connection?)</comment>');
        }
    }

    // ── --test flag ───────────────────────────────────────────────────────

    private function runDriverTests(): void
    {
        $this->line('');
        $this->line('<options=bold>Driver Tests (--test)</>');

        $this->testTesseractDriver();

        $cloudDrivers = ['google', 'aws', 'azure', 'claude', 'openai'];
        foreach ($cloudDrivers as $driver) {
            $this->line("  <comment>⚠ {$driver}: skipped — needs live credentials</comment>");
        }
    }

    private function testTesseractDriver(): void
    {
        // Check if tesseract binary is available
        $version = $this->runBinaryCheck('tesseract', ['--version']);
        if ($version === null) {
            $this->line('  <comment>⚠ tesseract: skipped — binary not found</comment>');
            return;
        }

        try {
            // Create a 1x1 white PNG in memory
            $tempFile = sys_get_temp_dir() . '/ocr_doctor_test_' . bin2hex(random_bytes(4)) . '.png';

            if (extension_loaded('gd')) {
                $img = imagecreatetruecolor(100, 30);
                $white = imagecolorallocate($img, 255, 255, 255);
                $black = imagecolorallocate($img, 0, 0, 0);
                imagefill($img, 0, 0, $white);
                imagestring($img, 5, 5, 5, 'TEST', $black);
                imagepng($img, $tempFile);
                imagedestroy($img);
            } else {
                // Minimal 1x1 white PNG (binary)
                $png = base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg=='
                );
                file_put_contents($tempFile, $png);
            }

            $driver = new TesseractDriver(config('smart-ocr.drivers.tesseract', []));
            $result = $driver->extract($tempFile, []);

            @unlink($tempFile);

            $this->line('  <fg=green>✓ tesseract: pass</>');
        } catch (\Throwable $e) {
            @unlink($tempFile ?? '');
            $this->line('  <error>✗ tesseract: fail — ' . $e->getMessage() . '</error>');
        }
    }

    // ── Utilities ─────────────────────────────────────────────────────────

    /**
     * Run a binary with given args via proc_open. Returns stdout+stderr combined, or null if failed.
     */
    private function runBinaryCheck(string $binary, array $args): ?string
    {
        $parts = array_map('escapeshellarg', array_merge([$binary], $args));
        $cmd   = implode(' ', $parts);

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0 && empty(trim((string) $stdout)) && empty(trim((string) $stderr))) {
            return null;
        }

        $output = trim((string) $stdout . (string) $stderr);
        return $output !== '' ? $output : null;
    }
}
