<?php declare(strict_types=1);
namespace LaravelSmartOCR\Output;
use LaravelSmartOCR\Results\OcrResult;

class MarkdownRenderer
{
    public function render(OcrResult $result): string
    {
        $md = '';

        // Use blocks/lines if available, fall back to plain text
        $blocks = $result->blocks();
        $tables = $result->tables();

        if (!empty($blocks)) {
            $md = $this->renderBlocks($blocks, $tables);
        } elseif (!empty($result->lines())) {
            $md = $this->renderLines($result->lines());
        } else {
            $md = $result->text();
        }

        // Inject tables at their approximate position
        // (already handled in renderBlocks; append if only text was available)
        if (empty($blocks) && !empty($tables)) {
            $md .= "\n\n" . implode("\n\n", array_map([$this, 'renderTable'], $tables));
        }

        return trim($md);
    }

    private function renderBlocks(array $blocks, array $tables): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $text = $block['text'] ?? '';
            $type = strtolower($block['type'] ?? 'line');
            if (empty(trim($text))) continue;

            if (in_array($type, ['title', 'heading', 'section_header'])) {
                $parts[] = '## ' . trim($text);
            } else {
                $parts[] = trim($text);
            }
        }
        foreach ($tables as $table) {
            $parts[] = $this->renderTable($table);
        }
        return implode("\n\n", $parts);
    }

    private function renderLines(array $lines): string
    {
        return implode("\n", array_map(fn($l) => trim($l['text'] ?? ''), $lines));
    }

    public function renderTable(array $table): string
    {
        $headers = $table['headers'] ?? [];
        $rows    = $table['rows'] ?? [];

        if (empty($headers) && !empty($rows)) {
            $headers = array_keys((array)($rows[0] ?? []));
        }

        if (empty($headers)) return '';

        $header = '| ' . implode(' | ', array_map('strval', $headers)) . ' |';
        $sep    = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';
        $body   = implode("\n", array_map(function ($row) use ($headers) {
            $cells = array_map(fn($h) => str_replace('|', '\\|', (string)($row[$h] ?? array_values((array)$row)[array_search($h, array_values(array_keys((array)$row))) ?? 0] ?? '')), $headers);
            return '| ' . implode(' | ', $cells) . ' |';
        }, $rows));

        return implode("\n", array_filter([$header, $sep, $body]));
    }
}
