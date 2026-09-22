<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use InvalidArgumentException;

/**
 * Splits a document into pieces small enough for one extraction call.
 *
 * Breaks fall between paragraphs (blank lines) where possible, then between
 * lines, and never inside a line unless that line alone is over the limit.
 * Pieces do not overlap and, joined back together, give the whole document,
 * so every item is read exactly once and every quote stays a quote from the
 * original.
 */
final readonly class DocumentChunker
{
    /** @param positive-int $maxChars */
    public function __construct(private int $maxChars)
    {
        if ($maxChars < 1) {
            throw new InvalidArgumentException('Chunk size must be positive.');
        }
    }

    /** @return non-empty-list<string> */
    public function split(string $document): array
    {
        if (mb_strlen($document) <= $this->maxChars) {
            return [$document];
        }

        $chunks = [];
        $current = '';
        foreach ($this->pieces($document) as $piece) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) > $this->maxChars) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $piece;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [$document] : $chunks;
    }

    /**
     * The document as a sequence of paragraphs, each no longer than the limit
     * (oversized ones are broken into lines, oversized lines into slices).
     *
     * @return list<string>
     */
    private function pieces(string $document): array
    {
        $pieces = [];
        foreach (preg_split('/(?<=\n)(?=[ \t]*\r?\n)/', $document) ?: [] as $paragraph) {
            if (mb_strlen($paragraph) <= $this->maxChars) {
                $pieces[] = $paragraph;

                continue;
            }
            foreach (preg_split('/(?<=\n)/', $paragraph, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                array_push($pieces, ...mb_str_split($line, $this->maxChars));
            }
        }

        return $pieces;
    }
}
