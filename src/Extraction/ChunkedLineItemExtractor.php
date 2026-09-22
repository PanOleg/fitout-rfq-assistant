<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Llm\Exceptions\LlmOutputTruncated;

/**
 * Large bills are where the tool saves the most time, and they are also the
 * ones that overflow a single call. This reads them in pieces and merges the
 * results. Each piece after the first is given the title, table header and
 * section headings above it (PieceContext), so it is not read blind.
 *
 * If a piece still produces more output than one call allows, it is halved at
 * a line break and each half read again, down to $minChars. A piece that small
 * (or a single line) is retried once, then fails the tender.
 */
final readonly class ChunkedLineItemExtractor implements LineItemExtractor
{
    /** @param positive-int $maxChars */
    public function __construct(
        private LineItemExtractor $inner,
        private int $maxChars,
        private int $minChars = 2_000,
        // Off only to measure what the context is worth: rfq:eval --without-context.
        private bool $withContext = true,
    ) {}

    public function extract(string $document, string $context = ''): ExtractionResult
    {
        return ExtractionResult::merge(array_map(
            fn (array $range): ExtractionResult => $this->extractPiece($document, substr($document, $range[0], $range[1]), $range[0], $context),
            $this->ranges($document),
        ));
    }

    /**
     * Where the pieces of $document are, as [byte offset, byte length], so each
     * can be read on its own — by a separate queue job — with extractRange().
     *
     * @return non-empty-list<array{int, int}>
     */
    public function ranges(string $document): array
    {
        $ranges = [];
        $offset = 0;
        foreach ((new DocumentChunker($this->maxChars))->split($document) as $piece) {
            $ranges[] = [$offset, strlen($piece)];
            $offset += strlen($piece);
        }

        return $ranges;
    }

    /** Reads one piece of $document, with the context before it and the same overflow handling as extract(). */
    public function extractRange(string $document, int $offset, int $length): ExtractionResult
    {
        return $this->extractPiece($document, substr($document, $offset, $length), $offset, '');
    }

    /** $offset is where $piece starts in $document; every piece after the first is given the context before it. */
    private function extractPiece(string $document, string $piece, int $offset, string $outer, bool $retried = false): ExtractionResult
    {
        $context = $offset === 0 || ! $this->withContext ? $outer : PieceContext::before($document, $offset);

        try {
            return $this->inner->extract($piece, $context);
        } catch (LlmOutputTruncated $e) {
            // The overflowing answer was paid for: whatever comes next carries its cost.
            try {
                $halves = mb_strlen($piece) > $this->minChars ? self::halve($piece) : null;
                if ($halves === null) {
                    // A small piece does not overflow by being too long: the answer ran away
                    // (seen in evals: 16k tokens for a document that normally takes 1.2k).
                    // That is a sampling accident, so it gets one more try.
                    if ($retried) {
                        throw $e;
                    }

                    return $this->extractPiece($document, $piece, $offset, $outer, retried: true)->withWastedUsage($e->usage);
                }

                return ExtractionResult::merge([
                    $this->extractPiece($document, $halves[0], $offset, $outer),
                    $this->extractPiece($document, $halves[1], $offset + strlen($halves[0]), $outer),
                ])->withWastedUsage($e->usage);
            } catch (LlmOutputTruncated $again) {
                throw $again === $e ? $e : $again->plus($e->usage);
            }
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
