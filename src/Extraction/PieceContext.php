<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\Unit;

/**
 * What a reader of one piece of a long bill would need from the pages before
 * it: the document's title block, the table header in force, and the section
 * heading the piece sits under ("FLOOR FINISHES").
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

    /**
     * Only the nearest heading: an earlier sibling ("SUSPENDED CEILINGS" above
     * "FLOOR FINISHES") is no longer in force and would only mislead.
     */
    private const HEADINGS = 1;

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
        $headings = array_slice(array_values(array_filter($lines, self::isHeading(...))), -self::HEADINGS);

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

    /** A short line in capitals with no quantity in it, e.g. "FLOOR FINISHES" or "K10 PARTITIONS". */
    private static function isHeading(string $line): bool
    {
        $text = trim($line);

        return mb_strlen($text) >= 3 && mb_strlen($text) <= 60
            && preg_match('/\p{Lu}{3}/u', $text) === 1
            && mb_strtoupper($text) === $text
            && ! self::isMeasured($text);
    }

    private static function isMeasured(string $line): bool
    {
        return preg_match('~\d\s*(?:'.Unit::aliasPattern().')(?![a-z0-9])~i', $line) === 1;
    }
}
