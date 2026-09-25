<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;

use LaravelSmartOCR\Results\OcrResult;

/**
 * Extracts structured data from an OcrResult using a schema.
 *
 * The schema can be:
 *  - A class name with #[Field] attributes (typed extraction)
 *  - An associative array ['fieldName' => 'description or type']
 */
class ExtractionService
{
    /**
     * Extract structured fields from an OCR result.
     *
     * @param OcrResult                  $result The OCR result to extract from.
     * @param string|array<string,mixed> $schema A class name or assoc array schema.
     */
    public function extract(OcrResult $result, string|array $schema): ExtractionResult
    {
        if (is_string($schema) && class_exists($schema)) {
            return $this->extractToClass($result, $schema);
        }

        return $this->extractToArray($result, (array)$schema);
    }

    private function extractToArray(OcrResult $result, array $schema): ExtractionResult
    {
        $text   = $result->text();
        $raw    = [];
        $fields = [];

        foreach ($schema as $fieldName => $description) {
            $value      = $this->extractFieldValue($text, (string)$fieldName);
            $confidence = $value !== null ? null : null; // confidence is null for rules engine
            $raw[$fieldName] = $value;
            $fields[]  = new FieldResult(
                name:         (string)$fieldName,
                value:        $value,
                confidence:   $confidence,
                sourceDriver: $result->provider(),
            );
        }

        return new ExtractionResult($raw, $raw, $fields);
    }

    private function extractToClass(OcrResult $result, string $className): ExtractionResult
    {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        $text = $result->text();
        $raw  = [];
        $fields = [];
        $args = [];

        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $fieldName = $param->getName();
                $value     = $this->extractFieldValue($text, $fieldName);

                if ($value === null && $param->isDefaultValueAvailable()) {
                    $value = $param->getDefaultValue();
                }

                $raw[$fieldName]  = $value;
                $args[]           = $value;
                $fields[]         = new FieldResult(
                    name:         $fieldName,
                    value:        $value,
                    confidence:   null,
                    sourceDriver: $result->provider(),
                );
            }
        }

        try {
            $instance = $reflection->newInstanceArgs($args);
        } catch (\Throwable) {
            $instance = $raw;
        }

        return new ExtractionResult($raw, $instance, $fields);
    }

    private function extractFieldValue(string $text, string $fieldName): mixed
    {
        // Simple keyword-based extraction: look for "fieldName: value" patterns
        $pattern = '/\b' . preg_quote($fieldName, '/') . '\s*[:\-]\s*([^\n]+)/i';
        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
