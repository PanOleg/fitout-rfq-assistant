<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\Unit;

/**
 * What a reader of one piece of a long bill would need from the pages before
 * it: the document's title block, the table header in force, and the headings
 * the piece sits under, outermost first ("LEVEL 2", "FLOOR FINISHES").
 * Without them a line like "Type A to open plan  585 m2" has no trade.
 *
 * Only whole lines from before the piece are used, so nothing here is new
 * text — and since quotes are checked against the piece alone, an item
 * lifted from the context is rejected.
 */
final class PieceContext
{
    private const MAX_CHARS = 2_000;

    private const TITLE_LINES = 4;

    /** Headings that open a part of the building or of the bill, not a trade section. */
    private const OUTER = '/^(?:(?:level|storey|building|block|zone|area|bill|part)\b|floor\s+\d|(?:\d+(?:st|nd|rd|th)|ground|first|second|third|fourth|fifth|basement|mezzanine|roof)\s+floor\b)/i';

    /** Words that make a line in capitals a note, not a heading: "ALL QUANTITIES PROVISIONAL". */
    private const NOTE_WORDS = '/\b(?:all|are|is|be|to be|shall|must|note|notes|provisional|include|includes|including|exclude|excluded|excluding|rates?|prices?|only|see|refer)\b/i';

    public static function before(string $document, int $offset): string
    {
        $all = array_map(rtrim(...), explode("\n", substr($document, 0, $offset)));
        $lines = array_values(array_filter($all, static fn (string $l): bool => trim($l) !== ''));

        // The title block: the opening lines up to the first blank line, never a measured line.
        $title = [];
        foreach ($all as $line) {
            if (trim($line) === '') {
                if ($title !== []) {
                    break;
                }

                continue;
            }
            if (count($title) === self::TITLE_LINES || self::isMeasured($line)) {
                break;
            }
            $title[] = $line;
        }
        $header = array_find(array_reverse($lines), self::isTableHeader(...));

        // Headings in force: a new heading replaces the last one at its level and
        // closes everything below it, so an earlier sibling ("SUSPENDED CEILINGS"
        // above "FLOOR FINISHES") does not linger, but "LEVEL 2" above both does.
        $open = [];
        foreach ($lines as $line) {
            $level = self::headingLevel($line);
            if ($level !== null) {
                $open = array_filter($open, static fn (int $l): bool => $l < $level, ARRAY_FILTER_USE_KEY);
                $open[$level] = trim($line);
            }
        }
        ksort($open);
        $headings = array_values($open);

        $context = [];
        foreach ([...$title, ...($header === null ? [] : [$header]), ...$headings] as $line) {
            if (! in_array($line, $context, true)) {
                $context[] = $line;
            }
        }

        return mb_substr(implode("\n", $context), 0, self::MAX_CHARS);
    }

    private static function isTableHeader(string $line): bool
    {
        return preg_match('/\b(?:qty|quantity|quant)\b/i', $line) === 1
            && preg_match('/\b(?:unit|description|desc)\b/i', $line) === 1;
    }

    /**
     * 1 for a part of the building or bill ("LEVEL 2", "BILL No. 3"), 2 for a
     * section in capitals ("FLOOR FINISHES", "K10 PARTITIONS"), and for
     * numbered headings the depth of the number ("2 Finishes" → 2,
     * "2.3 Floor finishes" → 3). Null for anything else, including notes in
     * capitals and measured lines.
     */
    private static function headingLevel(string $line): ?int
    {
        $text = trim($line);
        if (mb_strlen($text) < 3 || mb_strlen($text) > 60 || self::isMeasured($text) || preg_match(self::NOTE_WORDS, $text) === 1) {
            return null;
        }
        if (preg_match(self::OUTER, $text) === 1 && preg_match('/\p{Lu}/u', $text) === 1) {
            return 1;
        }
        if (preg_match('/^(\d+(?:\.\d+)*)\.?\s+\p{Lu}[\p{L} &\/,-]{2,}$/u', $text, $m) === 1) {
            return 1 + substr_count($m[1], '.') + 1;
        }
        if (preg_match('/\p{Lu}{3}/u', $text) === 1 && mb_strtoupper($text) === $text) {
            return 2;
        }

        return null;
    }

    private static function isMeasured(string $line): bool
    {
        return preg_match('~\d\s*(?:'.Unit::aliasPattern().')(?![a-z0-9])~i', $line) === 1;
    }
}
