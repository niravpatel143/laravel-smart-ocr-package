<?php declare(strict_types=1);
namespace LaravelSmartOCR\Services;

class PiiRedactor
{
    private const PATTERNS = [
        'email'  => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        'phone'  => '/(\+?\d[\d\s\-().]{7,}\d)/',
        'card'   => '/\b(?:\d[ \-]?){15,16}\b/',
        'iban'   => '/\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}\b/',
        'ssn'    => '/\b\d{3}-\d{2}-\d{4}\b/',
        'pan'    => '/\b[A-Z]{5}\d{4}[A-Z]\b/', // Indian PAN
    ];

    /**
     * Redact PII from text.
     * @param string[] $types  Which patterns to apply. Defaults to all.
     * @param string[] $custom Additional regex patterns.
     */
    public function redact(string $text, array $types = [], array $custom = []): string
    {
        $patterns = $types ? array_intersect_key(self::PATTERNS, array_flip($types)) : self::PATTERNS;

        foreach ($custom as $pattern) {
            $patterns[] = $pattern;
        }

        foreach ($patterns as $key => $pattern) {
            $mask  = is_string($key) ? strtoupper($key) . '_REDACTED' : 'REDACTED';
            $text = (string)preg_replace($pattern, "[{$mask}]", $text);
        }

        return $text;
    }
}
