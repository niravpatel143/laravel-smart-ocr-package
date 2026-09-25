<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;

/**
 * The result of a schema extraction operation.
 *
 * @property-read array $raw  Raw key-value data extracted (field name => value).
 * @property-read mixed $data Typed object if a class schema was used, otherwise same as $raw.
 */
class ExtractionResult
{
    /** @param FieldResult[] $fields */
    public function __construct(
        public readonly array $raw,
        public readonly mixed $data,
        /** @var FieldResult[] */
        private readonly array $fields = [],
    ) {}

    /**
     * Return the FieldResult for a named field.
     */
    public function field(string $name): ?FieldResult
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Return all FieldResult objects.
     * @return FieldResult[]
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Return fields that need review (confidence below threshold or null confidence).
     * @return FieldResult[]
     */
    public function needsReview(float $threshold = 0.85): array
    {
        return array_values(array_filter(
            $this->fields,
            fn(FieldResult $f) => !$f->isConfident($threshold)
        ));
    }

    /**
     * Return true if all fields meet the confidence threshold.
     */
    public function isConfident(float $threshold = 0.85): bool
    {
        return count($this->needsReview($threshold)) === 0;
    }

    /**
     * Create pending OcrReview rows for fields that need review.
     * Requires the migration to be run. Returns the created review rows.
     * @return \LaravelSmartOCR\Models\OcrReview[]
     */
    public function sendToReview(float $threshold = 0.85, string $documentHash = ''): array
    {
        if (!class_exists(\LaravelSmartOCR\Models\OcrReview::class)) {
            return [];
        }
        $reviews = [];
        foreach ($this->needsReview($threshold) as $field) {
            try {
                $review = \LaravelSmartOCR\Models\OcrReview::create([
                    'document_hash'   => $documentHash,
                    'driver'          => $field->sourceDriver,
                    'field_name'      => $field->name,
                    'extracted_value' => is_scalar($field->value) ? (string)$field->value : json_encode($field->value),
                    'confidence'      => $field->confidence,
                    'citation'        => $field->citation?->toArray(),
                    'status'          => 'pending',
                ]);
                event(new \LaravelSmartOCR\Events\ReviewRequested($review));
                $reviews[] = $review;
            } catch (\Throwable) {
                // Skip if table doesn't exist yet
            }
        }
        return $reviews;
    }
}
