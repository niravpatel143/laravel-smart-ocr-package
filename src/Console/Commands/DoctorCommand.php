<?php declare(strict_types=1);
namespace LaravelSmartOCR\Console\Commands;

use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature   = 'smart-ocr:doctor {--live : Run a minimal authenticated API call for each configured cloud driver}';
    protected $description = 'Check the Smart OCR environment: binaries, language packs, and cloud credentials';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold> Smart OCR — Doctor</>');
        $this->line(str_repeat('─', 50));

        $this->checkTesseract();
        $this->checkGhostscript();
        $this->checkCloudDrivers();

        $this->line('');
        return 0;
    }

    private function checkTesseract(): void
    {
        $this->line('');
        $this->line('<options=bold>Tesseract</>');

        $binary = $this->findBinary('tesseract');
        if ($binary) {
            $version = trim(shell_exec("{$binary} --version 2>&1 | head -1") ?? '');
            $this->line("  <fg=green>✓</> Binary: {$binary}");
            $this->line("  <fg=green>✓</> Version: {$version}");

            // Language packs
            $langs = trim(shell_exec("{$binary} --list-langs 2>&1") ?? '');
            $langList = array_filter(explode("\n", $langs));
            array_shift($langList); // remove header line
            $this->line('  <fg=green>✓</> Language packs: ' . implode(', ', array_slice($langList, 0, 10)) . (count($langList) > 10 ? ' (+' . (count($langList) - 10) . ' more)' : ''));
        } else {
            $this->line('  <fg=yellow>!</> Binary not found. Install Tesseract: https://github.com/UB-Mannheim/tesseract/wiki');
        }
    }

    private function checkGhostscript(): void
    {
        $this->line('');
        $this->line('<options=bold>Ghostscript (PDF → image for Tesseract)</>');

        $binary = $this->findBinary('gs') ?? $this->findBinary('gswin64c') ?? $this->findBinary('gswin32c');
        if ($binary) {
            $version = trim(shell_exec("{$binary} --version 2>&1") ?? '');
            $this->line("  <fg=green>✓</> Binary: {$binary} (v{$version})");
        } else {
            $this->line('  <fg=yellow>!</> Not found. Needed only for PDF OCR via Tesseract.');
        }
    }

    private function checkCloudDrivers(): void
    {
        $this->line('');
        $this->line('<options=bold>Cloud Drivers</>');

        $drivers = [
            'google' => [
                'label' => 'Google Cloud Vision',
                'check' => fn () => !empty(config('smart-ocr.drivers.google.api_key')) || !empty(config('smart-ocr.drivers.google.credentials')),
                'hint'  => 'Set SMART_OCR_GOOGLE_API_KEY or SMART_OCR_GOOGLE_CREDENTIALS',
            ],
            'aws' => [
                'label' => 'AWS Textract',
                'check' => fn () => !empty(config('smart-ocr.drivers.aws.key')) && !empty(config('smart-ocr.drivers.aws.secret')),
                'hint'  => 'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY',
            ],
            'azure' => [
                'label' => 'Azure AI Vision',
                'check' => fn () => !empty(config('smart-ocr.drivers.azure.endpoint')) && !empty(config('smart-ocr.drivers.azure.key')),
                'hint'  => 'Set SMART_OCR_AZURE_ENDPOINT and SMART_OCR_AZURE_KEY',
            ],
            'claude' => [
                'label' => 'Claude Vision',
                'check' => fn () => !empty(config('smart-ocr.drivers.claude.api_key')),
                'hint'  => 'Set ANTHROPIC_API_KEY',
            ],
            'openai' => [
                'label' => 'OpenAI Vision',
                'check' => fn () => !empty(config('smart-ocr.drivers.openai.api_key')),
                'hint'  => 'Set OPENAI_API_KEY',
            ],
        ];

        foreach ($drivers as $key => $driver) {
            $configured = ($driver['check'])();
            $icon  = $configured ? '<fg=green>✓</>' : '<fg=yellow>!</>';
            $state = $configured ? 'Configured' : 'Not configured — ' . $driver['hint'];
            $this->line("  {$icon} {$driver['label']}: {$state}");
        }

        if ($this->option('live')) {
            $this->line('');
            $this->line('  <fg=yellow>--live mode not yet implemented. Add credentials and test manually.</>');
        }
    }

    private function findBinary(string $name): ?string
    {
        $cmd = PHP_OS_FAMILY === 'Windows' ? "where {$name} 2>NUL" : "which {$name} 2>/dev/null";
        $result = trim(shell_exec($cmd) ?? '');
        return $result !== '' ? $result : null;
    }
}
