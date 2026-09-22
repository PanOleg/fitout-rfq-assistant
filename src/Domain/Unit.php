<?php

declare(strict_types=1);

namespace FitOut\Domain;

/** Units of measurement as they appear in UK bills of quantities. */
enum Unit: string
{
    case SquareMetre = 'm2';
    case LinearMetre = 'm';
    case CubicMetre = 'm3';
    case Number = 'nr';
    case Item = 'item';

    /**
     * How each unit is written in BoQs, specs and site emails, as regex
     * fragments over lower-cased text with ² and ³ already folded to 2 and 3.
     */
    private const ALIASES = [
        'm2' => ['sq\.?\s?m', 'sqm', 'm2'],
        'm3' => ['cu\.?\s?m', 'm3'],
        'm' => ['lin\.?\s?m', 'l/m', 'lm', 'm'],
        'nr' => ['nr', 'nos?\.?', 'ea', 'each'],
        'item' => ['items?', 'sum', 'lot'],
    ];

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $u): string => $u->value, self::cases());
    }

    /** The unit a written alias stands for, e.g. "sqm" → m2, "no." → nr. */
    public static function fromAlias(string $written): ?self
    {
        $written = mb_strtolower(trim($written));
        foreach (self::ALIASES as $unit => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('~^(?:'.$pattern.')$~', $written) === 1) {
                    return self::from($unit);
                }
            }
        }

        return null;
    }

    /** One alternation matching any alias, longest first so "m2" wins over "m". */
    public static function aliasPattern(): string
    {
        $all = array_merge(...array_values(self::ALIASES));
        usort($all, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return implode('|', $all);
    }
}
