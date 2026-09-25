<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;
use LaravelSmartOCR\Results\OcrResult;

class CitationMatcher
{
    public function findCitation(OcrResult $result, string $fieldName, mixed $value): ?FieldCitation
    {
        if ($value === null || $value === '') return null;
        $needle = strtolower(trim((string)$value));
        if (strlen($needle) < 2) return null;

        foreach ($result->words() as $word) {
            if ($this->matches($needle, strtolower(trim($word['text'] ?? '')))) {
                return new FieldCitation(
                    page: (int)($word['page'] ?? 1),
                    snippet: $word['text'] ?? '',
                    bbox: $word['bbox'] ?? $word['bounds'] ?? null,
                );
            }
        }
        foreach ($result->lines() as $line) {
            $lt = strtolower($line['text'] ?? '');
            if (str_contains($lt, $needle)) {
                return new FieldCitation(
                    page: (int)($line['page'] ?? 1),
                    snippet: substr($line['text'] ?? '', 0, 100),
                    bbox: $line['bbox'] ?? null,
                );
            }
        }
        $text = $result->text();
        $pos = stripos($text, (string)$value);
        if ($pos !== false) {
            return new FieldCitation(
                page: 1,
                snippet: trim(substr($text, max(0, $pos - 20), strlen((string)$value) + 40)),
                bbox: null,
            );
        }
        return null;
    }

    public function getWordConfidence(OcrResult $result, mixed $value): ?float
    {
        if ($value === null) return null;
        $needle = strtolower(trim((string)$value));
        foreach ($result->words() as $word) {
            if ($this->matches($needle, strtolower(trim($word['text'] ?? '')))) {
                $conf = $word['confidence'] ?? $word['conf'] ?? null;
                if ($conf !== null) return (float)$conf;
            }
        }
        return null;
    }

    private function matches(string $needle, string $hay): bool
    {
        if (empty($needle) || strlen($needle) < 3) return false;
        if (str_contains($hay, $needle)) return true;
        if (strlen($needle) <= 10 && abs(strlen($needle) - strlen($hay)) <= 1) {
            return levenshtein($needle, $hay) <= 1;
        }
        return false;
    }
}
