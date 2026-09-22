<?php

declare(strict_types=1);

/**
 * Builds the large eval cases 07–09 and their expectations, deterministically.
 *
 * Why generated: cases that separate models need size (tens of KB, several
 * pieces), file formats (PDF, XLSX) and many items — too many to type by
 * hand without mistakes. The expectations are true by construction: the
 * script writes each measured line and records exactly what it wrote. Traps
 * (rates, dimensions, provisional sums, totals, notes) are written as
 * non-items, and the ones that must be flagged are listed by keyword.
 *
 * Re-running with the same seed gives the same files. Run from the repo root:
 *   php evals/generators/generate.php
 */

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\MinimalPdf;

require __DIR__.'/../../vendor/autoload.php';

const OUT = __DIR__.'/../cases';

mt_srand(20260923);

/**
 * Per trade: heading, spec prefix, and line templates. "{tag}" becomes a unique
 * location tag, which is also the expectation's source_contains. Templates
 * marked with no trade word ("Type P3 as clause…", "Ditto…") can only be
 * classified from the section heading above them.
 *
 * @var array<string, array{string, string, list<array{string, string, int, int}>}>
 */
$trades = [
    'partitions' => ['PARTITIONS AND DRYLINING', 'K10', [
        ['Metal stud partition, 70mm C-stud, 1x15mm SoundBloc each side, {tag}', 'm2', 60, 420],
        ['Ditto but 2x15mm board each side, 60 min fire rated, {tag}', 'm2', 20, 140],
        ['Type P3 as specification clause K10/3.4, {tag}', 'm2', 15, 90],
        ['Plasterboard lining on resilient bars to existing columns, {tag}', 'm2', 8, 40],
        ['Frameless glazed screen, 12mm toughened, {tag}', 'm2', 10, 60],
    ]],
    'ceilings' => ['SUSPENDED CEILINGS', 'K40', [
        ['Suspended ceiling, 600x600 mineral tile on 24mm exposed grid, {tag}', 'm2', 150, 700],
        ['Type C2 moisture resistant tile, {tag}', 'm2', 10, 60],
        ['MF plasterboard bulkhead to perimeter, {tag}', 'm', 20, 140],
        ['Type C4 as clause K40/2.1, {tag}', 'm2', 12, 80],
    ]],
    'doors' => ['DOORS AND IRONMONGERY', 'L20', [
        ['Solid core timber doorset with vision panel, ironmongery set IM-02, {tag}', 'nr', 2, 16],
        ['FD30 doorset, single leaf, with door closer, {tag}', 'nr', 1, 10],
        ['Type D5 as door schedule, {tag}', 'nr', 1, 6],
    ]],
    'joinery' => ['JOINERY', 'N10', [
        ['Tea point base and wall units with quartz worktop, {tag}', 'item', 1, 1],
        ['Vanity unit with solid surface top, {tag}', 'nr', 1, 6],
        ['MDF skirting, 100mm, painted, {tag}', 'm', 40, 220],
        ['WC cubicles, full height, {tag}', 'nr', 2, 8],
    ]],
    'flooring' => ['FLOOR FINISHES', 'M50', [
        ['Carpet tiles 500x500, loop pile, {tag}', 'm2', 150, 690],
        ['Type F2 to WCs and cleaner\'s store, {tag}', 'm2', 10, 50],
        ['Luxury vinyl tile to tea point and breakout, {tag}', 'm2', 20, 90],
        ['Ditto but slip resistant, {tag}', 'm2', 5, 30],
        ['Recessed entrance matting, {tag}', 'm2', 4, 14],
    ]],
    'decoration' => ['DECORATION', 'M60', [
        ['Two coats emulsion on mist coat to new walls, {tag}', 'm2', 200, 900],
        ['Type W3 to meeting room walls, {tag}', 'm2', 20, 90],
        ['Manifestation to glazed screens, two bands, {tag}', 'm', 10, 60],
    ]],
    'mechanical' => ['MECHANICAL SERVICES', 'U10', [
        ['Four-pipe fan coil unit, ceiling void mounted, {tag}', 'nr', 4, 24],
        ['Supply and extract ductwork, galvanised, {tag}', 'm', 40, 300],
        ['Linear slot diffuser, 1200mm, {tag}', 'nr', 6, 36],
        ['Type M4 as mechanical schedule, {tag}', 'nr', 2, 12],
    ]],
    'electrical' => ['ELECTRICAL SERVICES', 'V20', [
        ['LED linear pendant, DALI dimmable, {tag}', 'nr', 12, 64],
        ['Double switched socket outlet, {tag}', 'nr', 20, 96],
        ['Floor box with four sockets and two data outlets, {tag}', 'nr', 6, 30],
        ['Cable basket containment, 100mm, {tag}', 'm', 40, 210],
        ['Type E7 luminaire as lighting schedule, {tag}', 'nr', 4, 20],
    ]],
    'plumbing' => ['PUBLIC HEALTH', 'R10', [
        ['WC pan and cistern, close coupled, {tag}', 'nr', 2, 8],
        ['Wash hand basin with mixer tap, {tag}', 'nr', 2, 8],
        ['Cold water pipework, copper, 22mm, {tag}', 'm', 10, 60],
    ]],
    'fire_protection' => ['FIRE PROTECTION', 'W60', [
        ['Sprinkler head, relocate to suit new ceiling layout, {tag}', 'nr', 10, 48],
        ['Intumescent coating to exposed steel, 60 min, {tag}', 'm2', 20, 120],
        ['Fire stopping to service penetrations, {tag}', 'item', 1, 1],
    ]],
];

$prose = [
    'partitions' => 'Partitions are to achieve the acoustic ratings on drawing A-310. Head details to allow for slab deflection. Board joints taped and filled ready for decoration.',
    'ceilings' => 'Grid to be installed level to within tolerance. Tiles are to be cut neatly at perimeters and around services. Access panels as required by the services engineer.',
    'doors' => 'Door leaves to be factory finished. Ironmongery to be submitted for approval before ordering. Fire doors certified to the stated rating.',
    'joinery' => 'All joinery to be manufactured from FSC certified material. Shop drawings to be submitted before manufacture. Edges to be lipped.',
    'flooring' => 'Subfloor to be prepared to the manufacturer\'s requirements. Transitions between finishes to be flush. Samples of each type to be approved before laying.',
    'decoration' => 'Surfaces to be prepared, filled and sanded before the mist coat. Colours as the finishes legend. Protect adjacent finishes throughout.',
    'mechanical' => 'All mechanical work to be commissioned and witnessed. Existing services to be isolated before strip out. Labels to be fixed to all new equipment.',
    'electrical' => 'All electrical work to comply with BS 7671. Circuits to be tested and certified. Emergency lighting to be integrated with the new layout.',
    'plumbing' => 'Sanitaryware to be white vitreous china unless noted. Pipework to be pressure tested before concealment. Isolation valves to each appliance.',
    'fire_protection' => 'Works to be carried out by a third party accredited installer. Certificates to be provided on completion. Sprinkler alterations coordinated with the insurer.',
];

/** @var list<array{trade: string, quantity: int, unit: string, source_contains: string}> $expected */
$expected = [];
$tags = [];

/** A unique location tag, e.g. "area 2-07" — also the expectation's fragment. */
$tag = static function (string $prefix) use (&$tags): string {
    $tags[$prefix] = ($tags[$prefix] ?? 0) + 1;

    return sprintf('area %s-%02d', $prefix, $tags[$prefix]);
};

/**
 * One measured line for $trade: returns [description, quantity, unit] and records the expectation.
 *
 * @return array{string, int, string}
 */
$item = static function (string $trade, string $prefix) use ($trades, $tag, &$expected): array {
    $templates = $trades[$trade][2];
    [$template, $unit, $min, $max] = $templates[mt_rand(0, count($templates) - 1)];
    $where = $tag($prefix);
    $quantity = mt_rand($min, $max);
    $expected[] = ['trade' => $trade, 'quantity' => $quantity, 'unit' => $unit, 'source_contains' => $where];

    return [str_replace('{tag}', $where, $template), $quantity, $unit];
};

$write = static function (string $name, array $expected, array $warnings, ?int $chunkChars = null): void {
    $spec = ($chunkChars === null ? [] : ['chunk_chars' => $chunkChars]) + ['items' => $expected, 'warnings_about' => $warnings];
    file_put_contents(OUT."/{$name}.expected.json", json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    echo "{$name}: ".count($expected)." items\n";
};

// ---------------------------------------------------------------- 07: long text bill, three levels
$expected = [];
$lines = [
    'BILL No. 3 - CAT A+ FIT-OUT, LEVELS 1 TO 4, 12 WHARF ROAD, BRISTOL',
    'Measured works. Quantities are net and measured in accordance with NRM2.',
    '',
    'PRELIMINARY NOTES',
    'The contractor is to allow for all labours, fixings, waste, protection and cleaning in the rates. Working hours are 08:00 to 18:00 on weekdays; noisy works outside these hours are to be agreed with the landlord in advance. The building remains partly occupied throughout, and access routes, lifts and loading bay bookings are to be coordinated with the facilities manager. All materials are to be new and as specified; alternatives require written approval. The contractor is to check all quantities against the drawings before pricing and report discrepancies within five working days of receipt of this bill.',
    'Drawings referred to are listed in Appendix A. Specification clauses are referenced in the left-hand column where they apply. Items marked Type are defined in the finishes legend on drawing A-210 and are to be priced as described there.',
    '',
    str_pad('Ref', 10).str_pad('Description', 72).str_pad('Qty', 8).'Unit',
    str_repeat('-', 96),
];
$traps = [
    1 => ['feature wall', 'Feature wall tiling to reception, 3 walls at 12 m2 per wall'],
    2 => ['canopy', 'Entrance canopy, powder coated, 4.0 x 2.5 m'],
    3 => ['baffles', 'Acoustic baffles, 6 per meeting room, 8 meeting rooms'],
    4 => ['louvre', 'Louvred screen to plant area, 2 panels at 3.6 m2 per panel'],
];
foreach ([1, 2, 3, 4] as $level) {
    $lines[] = '';
    $lines[] = "LEVEL {$level}";
    $lines[] = 'ALL QUANTITIES PROVISIONAL AND SUBJECT TO REMEASURE';
    foreach ($trades as $trade => [$heading, $clause]) {
        $lines[] = '';
        $lines[] = $heading;
        $lines[] = $prose[$trade];
        $count = mt_rand(4, 7);
        for ($i = 1; $i <= $count; $i++) {
            [$description, $quantity, $unit] = $item($trade, (string) $level);
            $ref = sprintf('%s/%d%02d', $clause, $level, $i);
            if (mb_strlen($description) > 64 && mt_rand(0, 2) === 0) {
                // Wrapped: the description runs onto a second line, which carries the quantity.
                $cut = (int) strrpos(substr($description, 0, 48), ' ');
                $lines[] = str_pad($ref, 10).substr($description, 0, $cut);
                $lines[] = str_pad('', 10).str_pad(substr($description, $cut + 1), 72).str_pad((string) $quantity, 8).$unit;
            } else {
                // At least two spaces before the quantity, however long the description.
                $lines[] = str_pad($ref, 10).str_pad($description, max(72, strlen($description) + 2)).str_pad((string) $quantity, 8).$unit;
            }
        }
        if ($trade === 'decoration') {
            $lines[] = str_pad(sprintf('%s/%d99', $clause, $level), 10).$traps[$level][1];
        }
        if ($trade === 'fire_protection') {
            $lines[] = str_pad(sprintf('PS-%d', $level), 10).str_pad('Provisional sum for out-of-hours working', 72).'GBP 5,000';
        }
    }
    $lines[] = str_pad('', 10)."Level {$level} carried to collection".str_repeat(' ', 40).'GBP ______';
}
$lines[] = '';
$lines[] = 'EXCLUSIONS';
$lines[] = '- Loose furniture and IT equipment (by client).';
$lines[] = '- Landlord\'s base build sprinkler mains.';
file_put_contents(OUT.'/07-long-nested-bill.txt', implode("\n", $lines)."\n");
$write('07-long-nested-bill', $expected, array_column($traps, 0));

// ---------------------------------------------------------------- 08: PDF, table drawn column by column
$expected = [];
$pages = [];
$rows = [['BILL No. 4 - FIT-OUT, 5TH FLOOR, 30 CHAPEL STREET, LEEDS', '', ''], ['', '', ''], ['Ref / Description', 'Qty', 'Unit']];
foreach (['partitions', 'ceilings', 'flooring', 'electrical', 'mechanical', 'doors'] as $trade) {
    $rows[] = ['', '', ''];
    $rows[] = [$trades[$trade][0], '', ''];
    foreach (range(1, mt_rand(5, 7)) as $i) {
        [$description, $quantity, $unit] = $item($trade, 'B');
        $rows[] = [sprintf('%s/5%02d  %s', $trades[$trade][1], $i, $description), (string) $quantity, $unit];
    }
    if ($trade === 'flooring') {
        $rows[] = ['M50/599  Stair nosings, 4 flights at 16 m per flight', '', ''];
    }
    if (count($rows) > 50) {
        $pages[] = $rows;
        $rows = [];
    }
}
$pages[] = $rows;
file_put_contents(OUT.'/08-pdf-bill-by-column.pdf', MinimalPdf::tablePagesDrawnByColumn($pages));
$write('08-pdf-bill-by-column', $expected, ['nosings']);

// ---------------------------------------------------------------- 09: spreadsheet with heading rows
$expected = [];
$writer = new Writer;
$writer->openToFile(OUT.'/09-xlsx-schedule.xlsx');
$writer->addRow(Row::fromValues(['Ref', 'Description', '', 'Qty', 'Unit']));
foreach (['ceilings', 'decoration', 'joinery', 'plumbing', 'fire_protection', 'partitions'] as $trade) {
    $writer->addRow(Row::fromValues(['', $trades[$trade][0]]));
    foreach (range(1, mt_rand(4, 6)) as $i) {
        [$description, $quantity, $unit] = $item($trade, 'X');
        $writer->addRow(Row::fromValues([sprintf('%s/9%02d', $trades[$trade][1], $i), $description, '', $quantity, $unit]));
    }
}
$writer->addRow(Row::fromValues(['PS-9', 'Provisional sum for statutory fees', '', '', 'GBP 2,500']));
$writer->addRow(Row::fromValues(['', 'Carried to summary', '', '', '']));
$writer->close();
$write('09-xlsx-schedule', $expected, []);
