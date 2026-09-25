<?php declare(strict_types=1);
namespace LaravelSmartOCR\Results;

class CostEstimate
{
    public function __construct(
        public readonly string $amount,    // decimal string e.g. "0.0015"
        public readonly string $currency,  // "USD"
        public readonly int $pages,
        public readonly int $tokens,
        public readonly bool $estimated,   // true = price from config, not invoiced
    ) {}

    public function toArray(): array
    {
        return [
            'amount'    => $this->amount,
            'currency'  => $this->currency,
            'pages'     => $this->pages,
            'tokens'    => $this->tokens,
            'estimated' => $this->estimated,
        ];
    }
}
