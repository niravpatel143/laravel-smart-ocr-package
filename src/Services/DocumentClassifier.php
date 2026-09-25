<?php declare(strict_types=1);
namespace LaravelSmartOCR\Services;
use LaravelSmartOCR\Results\OcrResult;

class DocumentClassifier
{
    /** Keyword rules per document type. */
    private array $rules = [
        'invoice'  => ['invoice', 'bill to', 'invoice number', 'inv #', 'due date', 'payment due', 'subtotal', 'line item'],
        'receipt'  => ['receipt', 'total paid', 'payment received', 'thank you for your purchase', 'change due'],
        'contract' => ['agreement', 'parties', 'whereas', 'hereinafter', 'signature', 'signed by', 'terms and conditions'],
        'id'       => ['date of birth', 'passport', 'driver license', 'license no', 'id number', 'expiry date'],
    ];

    /**
     * Classify a document into one of the given types.
     * Returns ['type' => 'invoice', 'confidence' => 0.75, 'scores' => ['invoice' => 5, ...]]
     */
    public function classify(OcrResult $result, array $types = []): array
    {
        $text  = strtolower($result->text());
        $types = $types ?: array_keys($this->rules);
        $scores = [];

        foreach ($types as $type) {
            $keywords = $this->rules[$type] ?? [];
            $hits = 0;
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) $hits++;
            }
            $scores[$type] = $hits;
        }

        arsort($scores);
        $best  = array_key_first($scores);
        $total = array_sum($scores) ?: 1;
        $conf  = $scores[$best] / max($total, 1);

        return [
            'type'       => $best ?? 'other',
            'confidence' => round(min($conf, 1.0), 3),
            'scores'     => $scores,
        ];
    }
}
