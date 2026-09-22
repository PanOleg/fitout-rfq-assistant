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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $u): string => $u->value, self::cases());
    }
}
