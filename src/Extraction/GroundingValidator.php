<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\Trade;
use FitOut\Domain\TradeVocabulary;
use FitOut\Domain\Unit;

/**
 * Checks each extracted item against the document it claims to come from.
 *
 * Schema-constrained output guarantees shape, not truth. The failures that
 * matter in a tender are an item that is not in the document, a quantity or
 * unit the document does not state for that line — a rate multiplied out, a
 * dimension read as an area — and an item filed under a trade its own words
 * contradict. All are cheap to detect deterministically, so they are
 * detected here rather than asked about in a prompt.
 *
 * Every field the model fills is checked here, as far as it can be:
 * - source_text: a verbatim quote from the document;
 * - quantity, unit: the number the quote states, with that unit;
 * - trade: not contradicted by the quote's own words;
 * - spec_reference: written in the document, on or just before the quoted line;
 * - description: not a quote by design — the schema asks for a short normalised
 *   description ("Carpet tiles, 500x500"), so it cannot be matched verbatim. What
 *   can be checked is checked: it is not empty, and every number in it is written
 *   in the document, so it cannot carry an invented size or rating. Its wording
 *   is otherwise the model's; the quote next to it on the review screen is the
 *   evidence a person reads.
 */
final readonly class GroundingValidator
{
    /**
     * Bump when a check changes. It is part of the extraction cache key, so a
     * result accepted under weaker checks is never replayed as clean.
     */
    public const VERSION = '7';

    private TradeVocabulary $vocabulary;

    public function __construct(?TradeVocabulary $vocabulary = null)
    {
        $this->vocabulary = $vocabulary ?? TradeVocabulary::load();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string> problems; empty when the item is grounded
     */
    public function problems(array $item, string $document): array
    {
        $problems = [];

        $source = is_string($item['source_text'] ?? null) ? $item['source_text'] : '';
        if (trim($source) === '') {
            $problems[] = 'source_text is empty.';
        } elseif (! self::isQuoted($source, $document)) {
            $problems[] = 'source_text is not a verbatim quote from the document.';
        }

        $quantity = $item['quantity'] ?? null;
        if (! is_int($quantity) && ! is_float($quantity) || $quantity <= 0) {
            $problems[] = 'quantity must be a positive number.';
        } elseif ($source !== '') {
            $unit = Unit::tryFrom(is_string($item['unit'] ?? null) ? $item['unit'] : '');
            $problem = self::quantityProblem($source, (float) $quantity, $unit);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if (! is_string($item['description'] ?? null) || trim($item['description']) === '') {
            $problems[] = 'description is empty.';
        } elseif (($invented = self::numbersNotIn($item['description'], $document)) !== []) {
            $problems[] = 'description has numbers the document does not ('.implode(', ', $invented).'); describe only what the line says.';
        }

        $reference = $item['spec_reference'] ?? null;
        if ($reference !== null && (! is_string($reference) || ! self::isNear($reference, $source, $document))) {
            $problems[] = 'spec_reference is not written on or just before the quoted line; use the line\'s own reference or null.';
        }
        $trade = Trade::tryFrom(is_string($item['trade'] ?? null) ? $item['trade'] : '');
        if ($trade === null) {
            $problems[] = 'trade is not one of the known trades.';
        } elseif ($source !== '' && ($problem = $this->tradeProblem($source, $trade)) !== null) {
            $problems[] = $problem;
        }
        if (Unit::tryFrom(is_string($item['unit'] ?? null) ? $item['unit'] : '') === null) {
            $problems[] = 'unit is not one of the known units.';
        }

        return $problems;
    }

    /** Whether $quote occurs in $document, ignoring whitespace, case and typographic quotes. */
    public static function isQuoted(string $quote, string $document): bool
    {
        return trim($quote) !== '' && str_contains(self::normalise($document), self::normalise($quote));
    }

    /** Whether $reference is written in the document within the quote or in the 200 characters before it. */
    private static function isNear(string $reference, string $quote, string $document): bool
    {
        $reference = self::normalise($reference);
        $text = self::normalise($document);
        $at = $quote === '' ? false : mb_strpos($text, self::normalise($quote));
        if ($reference === '' || $at === false) {
            return false;
        }
        $from = max(0, $at - 200);

        return str_contains(mb_substr($text, $from, $at - $from + mb_strlen(self::normalise($quote))), $reference);
    }

    /**
     * Numbers in $text that the document never writes. "m2" and "FD30" are not
     * numbers here (a digit glued to letters before it is part of a word).
     *
     * @return list<string>
     */
    private static function numbersNotIn(string $text, string $document): array
    {
        preg_match_all('/(?<![\p{L}\d.,])\d+(?:[.,]\d+)*/u', $text, $m);
        preg_match_all('/\d+(?:[.,]\d+)*/u', $document, $d);
        $known = array_flip(array_map(static fn (string $n): string => str_replace(',', '', $n), $d[0]));

        return array_values(array_unique(array_filter($m[0], static fn (string $n): bool => ! isset($known[str_replace(',', '', $n)]))));
    }

    private static function normalise(string $text): string
    {
        $text = str_replace(['’', '‘', '“', '”', '–', '—', '²', '³'], ["'", "'", '"', '"', '-', '-', '2', '3'], $text);

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text)));
    }

    /**
     * The trade is the model's judgement, so it is not proven here — only
     * contradicted. An item is flagged when its quote has no word of its own
     * trade but does have words of another one ("carpet tiles" as ceilings).
     * A quote with no trade words at all, or with words of several trades
     * including its own ("door and frame, including decoration"), passes.
     *
     * Where the work goes is not what it is: "Type B to WCs" under FLOOR
     * FINISHES is flooring, so a word right after "to", "in", "at"… does not
     * count as another trade's word.
     */
    private function tradeProblem(string $source, Trade $trade): ?string
    {
        $locations = implode('|', array_map(static fn (string $w): string => preg_quote($w, '~'), $this->vocabulary->locations));
        $text = $locations === '' ? self::normalise($source) : (string) preg_replace(
            '~\b(?:'.$locations.')\s+(?:(?:the|all|new|existing)\s+)*[\p{L}-]+(?:\s+(?:and|&)\s+[\p{L}-]+)?~u',
            ' ',
            self::normalise($source),
        );
        $vocabulary = $this->vocabulary;
        $said = static function (Trade $t) use ($text, $vocabulary): ?string {
            foreach ($vocabulary->words($t) as $keyword) {
                if (preg_match('~\b(?:'.$keyword.')\b~u', $text, $m) === 1) {
                    return $m[0];
                }
            }

            return null;
        };

        if ($said($trade) !== null) {
            return null;
        }

        $others = [];
        foreach (Trade::cases() as $other) {
            if ($other !== $trade && ($word = $said($other)) !== null) {
                $others[] = "{$other->value} (\"{$word}\")";
            }
        }

        return $others === [] ? null
            : "trade {$trade->value} is not supported by source_text, which reads as ".implode(' or ', $others).'; correct the trade or explain it in warnings.';
    }

    /**
     * A number being somewhere in the quote is not enough: "3 floors at 420 m2
     * per floor" contains 420, and "matting 3.0 x 2.5 m" contains 2.5 m. The
     * quantity must be the number the line states as its quantity — the one
     * carrying a unit — and that unit must be the item's unit.
     */
    private static function quantityProblem(string $source, float $quantity, ?Unit $unit): ?string
    {
        $numbers = self::numbersIn($source);
        $same = array_values(array_filter($numbers, static fn (array $n): bool => abs($n['value'] - $quantity) < 0.0005));
        $q = self::format($quantity);

        if ($same === []) {
            return "quantity {$q} does not appear in source_text; quantities must be stated, not calculated.";
        }

        $stated = array_values(array_filter($numbers, static fn (array $n): bool => ! $n['dimension'] && ! $n['rate'] && ! $n['other']));
        // When the line writes a unit anywhere (a rate counts), only numbers carrying one are
        // quantities — in "3 floors at 420 m2 per floor" the bare 3 is a multiplier. Otherwise
        // (e.g. "floor boxes x 22") a bare number has to do.
        $writesUnit = array_any($numbers, static fn (array $n): bool => $n['unit'] !== null && ! $n['dimension']);
        $candidates = array_values(array_filter(
            $writesUnit ? array_filter($stated, static fn (array $n): bool => $n['unit'] !== null) : $stated,
            static fn (array $n): bool => abs($n['value'] - $quantity) < 0.0005,
        ));

        if ($candidates === []) {
            return match (true) {
                array_any($same, static fn (array $n): bool => $n['rate']) => "quantity {$q} is a rate (\"per …\"), not a total; do not multiply it out — skip the item and warn.",
                array_any($same, static fn (array $n): bool => $n['dimension']) => "quantity {$q} is a dimension (\"… x …\"), not a measured quantity; skip the item and warn if no quantity is stated.",
                array_any($same, static fn (array $n): bool => $n['other']) => "quantity {$q} is part of a reference, range or price in source_text, not a quantity.",
                default => "quantity {$q} is not the number source_text states with a unit.",
            };
        }

        $units = array_values(array_unique(array_filter(array_map(static fn (array $n): ?string => $n['unit']?->value, $candidates))));
        if ($unit !== null && $units !== [] && ! in_array($unit->value, $units, true)) {
            return "unit {$unit->value} does not match source_text, which gives {$q} ".implode('/', $units).'.';
        }

        return null;
    }

    /**
     * Every number in the text that could be read as a quantity, with the unit
     * written right after it (if any) and whether it is part of a dimension
     * ("600x600", "3.0 x 2.5 m", "3.2 m high") or a rate ("420 m2 per floor").
     *
     * `other` marks numbers glued to a reference ("K10/120", "PS-1", "FD30"),
     * money ("£5,000") and the ends of ranges ("levels 1-3").
     *
     * @return list<array{value: float, unit: ?Unit, dimension: bool, rate: bool, other: bool}>
     */
    private static function numbersIn(string $text): array
    {
        $text = str_replace('×', 'x', self::normalise($text));
        $aliases = Unit::aliasPattern();

        preg_match_all(
            '~\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?~',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $numbers = [];
        foreach ($matches[0] as [$raw, $offset]) {
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($raw));

            $unit = null;
            $rate = false;
            $size = false;
            if (preg_match('~^\s*(?:\|\s*)?('.$aliases.')(?![a-z0-9])~', $after, $u) === 1) {
                $unit = Unit::fromAlias($u[1]);
                $rest = substr($after, strlen($u[0]));
                $rate = preg_match('~^\s*(?:per\b|/\s*[a-z])~', $rest) === 1;
                // "3.2 m high" describes the thing, it does not measure the work ("long" is left
                // out: "45 m long" is usually the run being measured).
                $size = preg_match('~^\s*(?:high|wide|deep|thick|girth|diameter|dia)\b~', $rest) === 1;
            }

            $numbers[] = [
                'value' => (float) str_replace(',', '', $raw),
                'unit' => $unit,
                'dimension' => $size || preg_match('~\d\s*x\s*$~', $before) === 1 || preg_match('~^\s*(?:mm|m)?\s*x\s*\d~', $after) === 1,
                'rate' => $rate,
                'other' => preg_match('~[a-z0-9./£$€-]$~', $before) === 1 || preg_match('~^-\d~', $after) === 1,
            ];
        }

        return $numbers;
    }

    private static function format(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
