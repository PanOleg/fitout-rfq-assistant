<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Extraction\PieceContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PieceContextTest extends TestCase
{
    private const DOCUMENT = <<<'DOC'
    SCHEDULE OF WORKS — 2ND FLOOR, 40 MARKET STREET, LEEDS
    Quantities are net.

    Ref     Description                                  Qty    Unit

    SUSPENDED CEILINGS
    C-01    Type A, 600x600 tile to open plan            540    m2

    FLOOR FINISHES
    F-01    Type A to open plan                          585    m2
    F-02    Type B to WCs                                 44    m2
    DOC;

    #[Test]
    public function a_piece_gets_the_title_the_table_header_and_the_heading_it_sits_under(): void
    {
        $offset = strpos(self::DOCUMENT, 'F-02');

        $context = PieceContext::before(self::DOCUMENT, (int) $offset);

        $this->assertSame(implode("\n", [
            'SCHEDULE OF WORKS — 2ND FLOOR, 40 MARKET STREET, LEEDS',
            'Quantities are net.',
            'Ref     Description                                  Qty    Unit',
            'FLOOR FINISHES',
        ]), $context);
    }

    #[Test]
    public function measured_lines_are_not_mistaken_for_headings(): void
    {
        $document = "TITLE\nNote one\nNote two\nNote three\nFLOOR FINISHES\nC-01 CARPET TILES 540 M2\nC-02 Next  12 m2\n";

        $context = PieceContext::before($document, (int) strpos($document, 'C-02'));

        $this->assertStringContainsString('FLOOR FINISHES', $context);

        $this->assertStringNotContainsString('CARPET', $context);
    }
}
