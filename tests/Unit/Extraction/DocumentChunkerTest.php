<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Extraction\DocumentChunker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentChunkerTest extends TestCase
{
    #[Test]
    public function a_short_document_is_one_piece(): void
    {
        $this->assertSame(["Carpet tiles, 640 m2\n"], (new DocumentChunker(100))->split("Carpet tiles, 640 m2\n"));
    }

    #[Test]
    public function pieces_break_between_paragraphs_and_add_up_to_the_document(): void
    {
        $document = "PARTITIONS\nStud wall, 312 m2\nDitto fire rated, 88 m2\n\nCEILINGS\nGrid ceiling, 780 m2\n\nFLOORING\nCarpet tiles, 690 m2\n";

        $pieces = (new DocumentChunker(60))->split($document);

        $this->assertSame($document, implode('', $pieces), 'nothing lost, nothing read twice');
        $this->assertGreaterThan(1, count($pieces));
        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(60, mb_strlen($piece));
        }
        $this->assertStringStartsWith('PARTITIONS', $pieces[0]);
        $this->assertStringEndsWith("Ditto fire rated, 88 m2\n", $pieces[0], 'a paragraph that fits is kept whole');
    }

    #[Test]
    public function an_oversized_paragraph_breaks_between_lines(): void
    {
        $lines = array_map(static fn (int $i): string => "Item {$i}, 10 nr\n", range(1, 20));
        $document = implode('', $lines);

        $pieces = (new DocumentChunker(60))->split($document);

        $this->assertSame($document, implode('', $pieces));
        foreach ($pieces as $piece) {
            $this->assertStringEndsWith("\n", $piece, 'no line is cut in half');
        }
    }
}
