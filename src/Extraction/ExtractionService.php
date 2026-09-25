<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;
use LaravelSmartOCR\Events\ExtractionNeedsReview;
use LaravelSmartOCR\Extraction\Engines\LlmEngine;
use LaravelSmartOCR\Extraction\Engines\RulesEngine;
use LaravelSmartOCR\Results\OcrResult;

class ExtractionService
{
    public function __construct(
        private readonly RulesEngine $rules = new RulesEngine(),
        private readonly CitationMatcher $matcher = new CitationMatcher(),
    ) {}

    public function extract(string|array|OcrResult $targetOrResult, OcrResult|string|array $resultOrTarget = null, array $options = []): ExtractionResult
    {
        // Support both argument orders:
        //   extract(OcrResult, string|array $target)  — canonical
        //   extract(string|array $target, OcrResult)  — test-friendly
        if ($targetOrResult instanceof OcrResult) {
            $ocrResult = $targetOrResult;
            $target = $resultOrTarget;
        } else {
            $target = $targetOrResult;
            $ocrResult = $resultOrTarget;
        }
        /** @var OcrResult $ocrResult */
        /** @var string|array $target */

        $schema = is_string($target) ? SchemaBuilder::fromClass($target) : SchemaBuilder::fromArray($target);
        $engine = $options['engine'] ?? config('smart-ocr.extraction.engine', 'rules');

        $raw = [];
        if ($engine === 'llm') {
            $llm = new LlmEngine($options['llm'] ?? []);
            $raw = $llm->extract($ocrResult, $schema);
        }
        if (empty($raw)) {
            $raw = $this->rules->extract($ocrResult, $schema);
        }

        // Build FieldResults with citations
        $fieldResults = [];
        foreach ($raw as $name => $value) {
            $fieldResults[] = new FieldResult(
                name: $name,
                value: $value,
                confidence: $this->matcher->getWordConfidence($ocrResult, $value),
                citation: $this->matcher->findCitation($ocrResult, $name, $value),
                sourceDriver: $ocrResult->provider(),
            );
        }

        $errors = [];
        if (is_string($target)) {
            try {
                $data = ClassHydrator::hydrate($target, $raw);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                $data = $raw;
            }
        } else {
            $data = $raw;
        }

        $result = new ExtractionResult($data, $errors, $raw, $fieldResults);

        $threshold = (float)($options['review_threshold'] ?? 0.85);
        if (!$result->isConfident($threshold)) {
            event(new ExtractionNeedsReview($result, $threshold));
        }

        return $result;
    }
}
