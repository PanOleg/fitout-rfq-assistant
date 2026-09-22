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

    /**
     * Words a line of that trade is written with, as regex fragments matched
     * from a word boundary over lower-cased text. Not a classifier: the trade
     * is still the model's call. These only catch a quote that plainly belongs
     * somewhere else — "carpet tiles" filed under ceilings.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Partitions => ['partition', 'stud', 'plasterboard', 'dry-?\s?lining', 'soundbloc', 'acoustic insulation', 'apr insulation', 'glazed screen'],
            self::Ceilings => ['ceiling', 'bulkhead', 'raft', 'mineral tile', 'exposed grid', 'suspended grid'],
            self::Doors => ['doors?\b', 'doorsets?', 'ironmongery', 'door closers?', 'fd\d0'],
            self::Joinery => ['joinery', 'kitchen', 'tea point', 'vanity', 'reception desk', 'shelving', 'skirting', 'panelling', 'worktop', 'wall units', 'base units', 'cupboard'],
            self::Flooring => ['carpet', 'lvt\b', 'vinyl', 'raised access floor', 'screed', 'matting', 'flooring', 'floor finish'],
            self::Decoration => ['paint', 'emulsion', 'decorat', 'wallcovering', 'wallpaper', 'manifestation', 'mist coat'],
            self::Mechanical => ['hvac', 'fan coil', 'fcus?\b', 'duct', 'grilles?', 'diffusers?', 'vrf\b', 'ahu\b', 'air handling', 'ventilation'],
            self::Electrical => ['light', 'luminaire', 'led\b', 'pendant', 'downlight', 'socket', 'small power', 'data\b', 'containment', 'cable', 'floor box', 'fire alarm', 'access control', 'dali\b'],
            self::Plumbing => ['plumbing', 'sanitary', 'wcs?\b', 'basins?', 'pans?\b', 'urinals?', 'sinks?\b', 'taps?\b', 'pipework', 'hot and cold water', 'cold water', 'waste pipe', 'drainage', 'shower'],
            self::FireProtection => ['sprinkler', 'fire[- ]?stopping', 'intumescent', 'fire curtain', 'fire protection', 'fire collar', 'fire batt'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
