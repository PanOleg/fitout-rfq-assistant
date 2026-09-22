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
     * as whole words over lower-cased text — "lighting" but not "lightweight",
     * "stud" but not "studio". Not a classifier: the trade is still the
     * model's call. These only catch a quote that plainly belongs somewhere
     * else — "carpet tiles" filed under ceilings.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Partitions => ['partitions?', 'studs?', 'stud ?work', 'plasterboard', 'dry-?\\s?lining', 'soundbloc', 'acoustic insulation', 'apr insulation', 'glazed screens?'],
            self::Ceilings => ['ceilings?', 'bulkheads?', 'rafts?', 'mineral tiles?', 'exposed grid', 'suspended grid', 'margins?', 'mf'],
            self::Doors => ['doors?', 'doorsets?', 'ironmongery', 'door closers?', 'fd\\d0'],
            self::Joinery => ['joinery', 'kitchens?', 'tea points?', 'vanity', 'reception desks?', 'shelving', 'skirtings?', 'panelling', 'worktops?', 'wall units', 'base units', 'cupboards?', 'cubicles?'],
            self::Flooring => ['carpets?', 'lvt', 'vinyl', 'raised access floor(?:ing)?', 'screed', 'matting', 'flooring', 'floor finish(?:es)?'],
            self::Decoration => ['paint(?:s|ed|ing|work)?', 'emulsion', 'decorat(?:e|ed|ing|ion|ions|or|ors)', 'wallcoverings?', 'wallpaper', 'manifestations?', 'mist coat'],
            self::Mechanical => ['hvac', 'fan coils?', 'fcus?', 'duct(?:s|work|ing)?', 'grilles?', 'diffusers?', 'vrf', 'ahus?', 'air handling', 'ventilation'],
            self::Electrical => ['light(?:s|ing)?', 'luminaires?', 'led', 'pendants?', 'downlights?', 'sockets?', 'small power', 'data', 'containment', 'cables?', 'cabling', 'floor box(?:es)?', 'fire alarm', 'access control', 'dali'],
            self::Plumbing => ['plumbing', 'sanitary(?:ware)?', 'wcs?', 'basins?', 'pans?', 'urinals?', 'sinks?', 'taps?', 'pipework', 'hot and cold water', 'cold water', 'waste pipes?', 'drainage', 'showers?'],
            self::FireProtection => ['sprinklers?', 'fire[- ]?stopping', 'intumescent', 'fire curtains?', 'fire protection', 'fire collars?', 'fire batts?'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
