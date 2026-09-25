<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction\Engines;
use LaravelSmartOCR\Results\OcrResult;

class RulesEngine
{
    public function extract(OcrResult $result, array $schema): array
    {
        $text = $result->text();
        $out  = [];
        foreach ($schema['properties'] ?? [] as $name => $def) {
            $out[$name] = $this->extractField($text, $name, $def);
        }
        return $out;
    }

    private function extractField(string $text, string $name, array $def): mixed
    {
        $type = $def['type'] ?? 'string';
        $desc = $def['description'] ?? $name;
        $keywords = array_unique(array_merge($this->words($name), $this->words($desc)));

        foreach ($keywords as $kw) {
            if (strlen($kw) < 3) continue;
            $pat = '/\b' . preg_quote($kw, '/') . '\b\s*[:#\-]?\s*([^\n\r]{1,80})/i';
            if (preg_match($pat, $text, $m)) {
                return $this->cast(trim($m[1]), $type);
            }
        }

        // Type-specific fallbacks
        if (in_array($type, ['number', 'integer'], true) || str_contains(strtolower($desc), 'total') || str_contains(strtolower($desc), 'amount')) {
            if (preg_match('/[$€£₹¥]?\s*([\d,]+\.?\d*)/u', $text, $m)) return $this->cast($m[1], $type);
        }
        if (str_contains(strtolower($desc), 'date')) {
            if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) return $m[1];
            if (preg_match('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $text, $m)) return $m[1];
        }

        return null;
    }

    private function words(string $str): array
    {
        $str = preg_replace('/([a-z])([A-Z])/', '$1 $2', $str) ?? $str;
        $str = str_replace(['_', '-'], ' ', $str);
        return array_values(array_filter(explode(' ', strtolower($str)), fn($w) => strlen($w) > 2));
    }

    private function cast(string $v, string $type): mixed
    {
        $v = trim($v, " \t\n\r\0\x0B$€£¥₹,");
        return match ($type) {
            'number' => is_numeric(str_replace(',', '', $v)) ? (float)str_replace(',', '', $v) : null,
            'integer' => is_numeric($v) ? (int)$v : null,
            'boolean' => in_array(strtolower($v), ['yes','true','1']),
            default => $v ?: null,
        };
    }
}
