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
    public function nested_headings_are_kept_outermost_first_and_siblings_are_closed(): void
    {
        $document = "SCHEDULE OF WORKS\n\nLEVEL 2\nSUSPENDED CEILINGS\nC-01 Grid ceiling  540 m2\nFLOOR FINISHES\nF-01 Type A  585 m2\nF-02 Type B  38 m2\n";

        $context = PieceContext::before($document, (int) strpos($document, 'F-02'));

        $this->assertSame("SCHEDULE OF WORKS\nLEVEL 2\nFLOOR FINISHES", $context);
    }

    #[Test]
    public function a_note_in_capitals_is_not_a_heading(): void
    {
        $document = "SCHEDULE\n\nFLOOR FINISHES\nALL QUANTITIES PROVISIONAL\nF-01 Type A  585 m2\nF-02 Type B  38 m2\n";

        $context = PieceContext::before($document, (int) strpos($document, 'F-02'));

        $this->assertStringContainsString('FLOOR FINISHES', $context);
        $this->assertStringNotContainsString('PROVISIONAL', $context);
    }

    #[Test]
    public function numbered_headings_nest_by_their_number(): void
    {
        $document = "SPECIFICATION\n\n2 Finishes\n2.1 Wall finishes\nW-01 Emulsion  860 m2\n2.2 Floor finishes\nF-01 Carpet tiles  585 m2\nF-02 Vinyl  38 m2\n";

        $context = PieceContext::before($document, (int) strpos($document, 'F-02'));

        $this->assertSame("SPECIFICATION\n2 Finishes\n2.2 Floor finishes", $context);
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
