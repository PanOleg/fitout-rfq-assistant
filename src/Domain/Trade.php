<?php

declare(strict_types=1);

namespace FitOut\Domain;

/**
 * The trade packages a fit-out main contractor tenders separately.
 * Case order is the order packages are presented in — roughly the order
 * they go on site.
 */
enum Trade: string
{
    case Partitions = 'partitions';
    case Ceilings = 'ceilings';
    case Doors = 'doors';
    case Joinery = 'joinery';
    case Flooring = 'flooring';
    case Decoration = 'decoration';
    case Mechanical = 'mechanical';
    case Electrical = 'electrical';
    case Plumbing = 'plumbing';
    case FireProtection = 'fire_protection';

    public function label(): string
    {
        return match ($this) {
            self::Partitions => 'Partitions & drylining',
            self::Ceilings => 'Suspended ceilings',
            self::Doors => 'Doors & ironmongery',
            self::Joinery => 'Joinery',
            self::Flooring => 'Floor finishes',
            self::Decoration => 'Decoration',
            self::Mechanical => 'Mechanical (HVAC)',
            self::Electrical => 'Electrical & lighting',
            self::Plumbing => 'Plumbing & sanitaryware',
            self::FireProtection => 'Fire protection',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
