<?php

declare(strict_types=1);

/*
 * Trade vocabulary for UK English bills of quantities.
 *
 * "trades": words a line of that trade is written with, as regex fragments
 * matched as whole words over lower-cased text ("lighting" but not
 * "lightweight"). They only let the validator catch a quote that plainly
 * contradicts the trade the model chose; the trade itself stays the model's
 * call, and an unknown word contradicts nothing.
 *
 * "locations": words after which a noun says where the work goes, not what it
 * is — "Type B to WCs" under FLOOR FINISHES is flooring, not plumbing.
 *
 * Another market or language is another file like this one, selected with
 * RFQ_VOCABULARY; the validator does not change.
 */

return [
    'trades' => [
        'partitions' => ['partitions?', 'studs?', 'stud ?work', 'plasterboard', 'dry-?\\s?lining', 'soundbloc', 'acoustic insulation', 'apr insulation', 'glazed screens?'],
        'ceilings' => ['ceilings?', 'bulkheads?', 'rafts?', 'mineral tiles?', 'exposed grid', 'suspended grid', 'margins?', 'mf'],
        'doors' => ['doors?', 'doorsets?', 'ironmongery', 'door closers?', 'fd\\d0'],
        'joinery' => ['joinery', 'kitchens?', 'tea points?', 'vanity', 'reception desks?', 'shelving', 'skirtings?', 'panelling', 'worktops?', 'wall units', 'base units', 'cupboards?', 'cubicles?'],
        'flooring' => ['carpets?', 'lvt', 'vinyl', 'raised access floor(?:ing)?', 'screed', 'matting', 'flooring', 'floor finish(?:es)?'],
        'decoration' => ['paint(?:s|ed|ing|work)?', 'emulsion', 'decorat(?:e|ed|ing|ion|ions|or|ors)', 'wallcoverings?', 'wallpaper', 'manifestations?', 'mist coat'],
        'mechanical' => ['hvac', 'fan coils?', 'fcus?', 'duct(?:s|work|ing)?', 'grilles?', 'diffusers?', 'vrf', 'ahus?', 'air handling', 'ventilation'],
        'electrical' => ['light(?:s|ing)?', 'luminaires?', 'led', 'pendants?', 'downlights?', 'sockets?', 'small power', 'data', 'containment', 'cables?', 'cabling', 'floor box(?:es)?', 'fire alarm', 'access control', 'dali'],
        'plumbing' => ['plumbing', 'sanitary(?:ware)?', 'wcs?', 'basins?', 'pans?', 'urinals?', 'sinks?', 'taps?', 'pipework', 'hot and cold water', 'cold water', 'waste pipes?', 'drainage', 'showers?'],
        'fire_protection' => ['sprinklers?', 'fire[- ]?stopping', 'intumescent', 'fire curtains?', 'fire protection', 'fire collars?', 'fire batts?'],
    ],
    'locations' => ['to', 'in', 'at', 'within', 'serving'],
];
