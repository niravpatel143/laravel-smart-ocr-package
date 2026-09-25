<?php declare(strict_types=1);
namespace LaravelSmartOCR\Output;

class Chunk
{
    public function __construct(
        public readonly string $text,
        public readonly int $startPage,
        public readonly int $endPage,
        public readonly ?string $heading,
        public readonly int $tokenEstimate,
    ) {}

    public function toArray(): array
    {
        return [
            'text'           => $this->text,
            'start_page'     => $this->startPage,
            'end_page'       => $this->endPage,
            'heading'        => $this->heading,
            'token_estimate' => $this->tokenEstimate,
        ];
    }
}

class Chunker
{
    public function chunk(string $markdown, int $maxTokens = 500, int $overlap = 50): array
    {
        $chunks = [];
        // Split by headings first
        $sections = preg_split('/^(#{1,3} .+)$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?? [$markdown];

        $currentHeading = null;
        $buffer = '';
        $page = 1;

        for ($i = 0; $i < count($sections); $i++) {
            $piece = $sections[$i];
            if (preg_match('/^#{1,3} .+/', $piece)) {
                // Flush buffer before new heading
                if (trim($buffer) !== '') {
                    foreach ($this->splitBySize($buffer, $maxTokens, $overlap, $currentHeading, $page) as $c) {
                        $chunks[] = $c;
                    }
                }
                $currentHeading = trim($piece);
                $buffer = '';
                continue;
            }
            $buffer .= $piece;
        }

        if (trim($buffer) !== '') {
            foreach ($this->splitBySize($buffer, $maxTokens, $overlap, $currentHeading, $page) as $c) {
                $chunks[] = $c;
            }
        }

        return $chunks;
    }

    /** @return Chunk[] */
    private function splitBySize(string $text, int $maxTokens, int $overlap, ?string $heading, int $page): array
    {
        $chunks = [];
        $paragraphs = preg_split('/\n\n+/', $text) ?? [$text];
        $buffer = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;

            $combined = $buffer . ($buffer ? "\n\n" : '') . $para;
            if ($this->estimateTokens($combined) <= $maxTokens) {
                $buffer = $combined;
            } else {
                if (trim($buffer) !== '') {
                    $chunks[] = new Chunk(trim($buffer), $page, $page, $heading, $this->estimateTokens($buffer));
                    // Overlap: keep last sentence
                    $sentences = preg_split('/(?<=[.!?])\s+/', $buffer) ?? [$buffer];
                    $overlapText = implode(' ', array_slice($sentences, -2));
                    $buffer = strlen($overlapText) <= $overlap * 5 ? $overlapText . "\n\n" . $para : $para;
                } else {
                    $buffer = $para;
                }
            }
        }

        if (trim($buffer) !== '') {
            $chunks[] = new Chunk(trim($buffer), $page, $page, $heading, $this->estimateTokens($buffer));
        }

        return $chunks;
    }

    private function estimateTokens(string $text): int
    {
        // ~4 chars per token (GPT approximation)
        return (int)ceil(strlen($text) / 4);
    }
}
