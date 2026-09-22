<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\Trade;
use FitOut\Domain\Unit;

/**
 * Checks each extracted item against the document it claims to come from.
 *
 * Schema-constrained output guarantees shape, not truth. The two failures that
 * matter in a tender are an item that is not in the document and a quantity
 * that is not in the document — both are cheap to detect deterministically,
 * so they are detected here rather than asked about in a prompt.
 */
final class GroundingValidator
{
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
        } elseif (! str_contains(self::normalise($document), self::normalise($source))) {
            $problems[] = 'source_text is not a verbatim quote from the document.';
        }

        $quantity = $item['quantity'] ?? null;
        if (! is_int($quantity) && ! is_float($quantity) || $quantity <= 0) {
            $problems[] = 'quantity must be a positive number.';
        } elseif ($source !== '' && ! self::mentionsNumber($source, (float) $quantity)) {
            $problems[] = "quantity {$quantity} does not appear in source_text; quantities must be stated, not calculated.";
        }

        if (! is_string($item['description'] ?? null) || trim($item['description']) === '') {
            $problems[] = 'description is empty.';
        }
        if (Trade::tryFrom(is_string($item['trade'] ?? null) ? $item['trade'] : '') === null) {
            $problems[] = 'trade is not one of the known trades.';
        }
        if (Unit::tryFrom(is_string($item['unit'] ?? null) ? $item['unit'] : '') === null) {
            $problems[] = 'unit is not one of the known units.';
        }

        return $problems;
    }

    private static function normalise(string $text): string
    {
        $text = str_replace(['’', '‘', '“', '”', '–', '—', '²', '³'], ["'", "'", '"', '"', '-', '-', '2', '3'], $text);

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text)));
    }

    private static function mentionsNumber(string $text, float $quantity): bool
    {
        preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/', $text, $matches);

        foreach ($matches[0] as $number) {
            if (abs((float) str_replace(',', '', $number) - $quantity) < 0.0005) {
                return true;
            }
        }

        return false;
    }
}
