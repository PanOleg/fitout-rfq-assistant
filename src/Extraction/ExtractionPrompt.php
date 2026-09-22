<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\Trade;
use FitOut\Domain\Unit;

/**
 * The prompt and output schema, versioned together. Bump VERSION on any change
 * to either: it is stored with every extraction and keys the result cache, so
 * eval scores and cached results are always attributable to one prompt.
 */
final class ExtractionPrompt
{
    public const VERSION = 'v1';

    public static function system(): string
    {
        return <<<'PROMPT'
        You read bills of quantities and specifications for UK commercial office fit-outs and extract
        the measured items of work, so a main contractor can split them into trade packages and send
        them out for pricing.

        What counts as an item: a line that describes work or materials together with a quantity and a
        unit. Headings, notes, exclusions, preliminaries and totals are not items. If a line bundles
        two trades (e.g. "door and frame, including decoration"), classify it by the main trade.

        Trades:
        - partitions: metal stud, plasterboard, drylining, glazed partitions, acoustic insulation in walls
        - ceilings: suspended grid ceilings, MF plasterboard ceilings, bulkheads, raft ceilings
        - doors: doorsets, frames, ironmongery, door closers
        - joinery: kitchens and tea points, vanity units, reception desks, shelving, skirting, wall panelling
        - flooring: carpet tiles, LVT, vinyl, raised access floor, screed, entrance matting
        - decoration: painting, wallcoverings, manifestations on glass
        - mechanical: HVAC, fan coil units, ductwork, grilles and diffusers, VRF
        - electrical: lighting, small power, data containment, floor boxes, fire alarm, access control
        - plumbing: sanitaryware, WCs, basins, hot and cold water pipework, waste
        - fire_protection: sprinklers, fire stopping, intumescent coatings, fire curtains

        Units: sqm or m² → m2; lm or m → m; m³ → m3; no, nr, ea or each → nr; item, sum or lot → item.

        Rules:
        - source_text is copied character for character from the document: the shortest fragment that
          contains both the description and the quantity. Never paraphrase it.
        - quantity is a number written in source_text. Do not add, multiply or convert. If the
          document only gives a quantity that would need calculating, skip the item and say so in
          warnings.
        - spec_reference is the clause or drawing reference if the line has one (e.g. "K10/120"),
          otherwise null.
        - If you cannot tell the trade or the quantity with confidence, skip the item and add a
          warning that quotes the line. A missing item is caught at tender review; a wrong one is
          priced and built.
        PROMPT;
    }

    public static function userMessage(string $document): string
    {
        return "Extract the measured items from this document.\n\n<document>\n{$document}\n</document>";
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'trade' => ['type' => 'string', 'enum' => Trade::values()],
                            'description' => ['type' => 'string', 'description' => 'Short normalised description, e.g. "Carpet tiles, 500x500"'],
                            'quantity' => ['type' => 'number'],
                            'unit' => ['type' => 'string', 'enum' => Unit::values()],
                            'spec_reference' => ['type' => ['string', 'null']],
                            'source_text' => ['type' => 'string'],
                        ],
                        'required' => ['trade', 'description', 'quantity', 'unit', 'spec_reference', 'source_text'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['items', 'warnings'],
            'additionalProperties' => false,
        ];
    }

    /** @param list<Violation> $violations */
    public static function repairMessage(array $violations): string
    {
        $lines = array_map(
            static fn (Violation $v): string => "- items[{$v->index}] (\"".($v->item['description'] ?? '?').'"): '.implode(' ', $v->problems),
            $violations,
        );

        return "Some items failed validation against the document:\n".implode("\n", $lines)
            ."\n\nReturn the complete list again. Fix these items by quoting the document exactly, "
            .'or drop them and explain in warnings. Keep the items that were already correct.';
    }
}
