<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Llm\Exceptions\LlmOutputTruncated;

/**
 * Large bills are where the tool saves the most time, and they are also the
 * ones that overflow a single call. This reads them in pieces and merges the
 * results.
 *
 * If a piece still produces more output than one call allows, it is halved at
 * a line break and each half read again, down to $minChars. Only a piece that
 * small (or a single line) which still overflows fails the tender.
 */
final readonly class ChunkedLineItemExtractor implements LineItemExtractor
{
    /** @param positive-int $maxChars */
    public function __construct(
        private LineItemExtractor $inner,
        private int $maxChars,
        private int $minChars = 2_000,
    ) {}

    public function extract(string $document): ExtractionResult
    {
        return ExtractionResult::merge(array_map(
            $this->extractPiece(...),
            (new DocumentChunker($this->maxChars))->split($document),
        ));
    }

    private function extractPiece(string $piece): ExtractionResult
    {
        try {
            return $this->inner->extract($piece);
        } catch (LlmOutputTruncated $e) {
            $halves = mb_strlen($piece) > $this->minChars ? self::halve($piece) : null;
            if ($halves === null) {
                throw $e;
            }

            return ExtractionResult::merge(array_map($this->extractPiece(...), $halves));
        }
    }

    /**
     * Splits at the line break nearest the middle, so no line is cut in two.
     *
     * @return ?array{string, string} null when the piece is a single line
     */
    private static function halve(string $piece): ?array
    {
        $middle = intdiv(strlen($piece), 2);
        $before = strrpos(substr($piece, 0, $middle), "\n");
        $after = strpos($piece, "\n", $middle);

        $candidates = array_filter(
            [$before === false ? null : $before + 1, $after === false ? null : $after + 1],
            static fn (?int $at): bool => $at !== null && $at > 0 && $at < strlen($piece),
        );
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (int $a, int $b): int => abs($a - $middle) <=> abs($b - $middle));

        return [substr($piece, 0, $candidates[0]), substr($piece, $candidates[0])];
    }
}
