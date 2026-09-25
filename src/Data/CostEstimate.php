<?php declare(strict_types=1);
namespace LaravelSmartOCR\Data;

/**
 * Cost estimate for an OCR operation.
 */
class CostEstimate
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency = 'USD',
        public readonly string $driver = '',
        public readonly int $pages = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'amount'   => $this->amount,
            'currency' => $this->currency,
            'driver'   => $this->driver,
            'pages'    => $this->pages,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            amount:   (float)($data['amount'] ?? 0.0),
            currency: (string)($data['currency'] ?? 'USD'),
            driver:   (string)($data['driver'] ?? ''),
            pages:    (int)($data['pages'] ?? 0),
        );
    }
}
