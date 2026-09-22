<?php

declare(strict_types=1);

namespace Tests\Unit\Ingestion;

use FitOut\Extraction\GroundingValidator;
use FitOut\Ingestion\DocumentText;
use FitOut\Ingestion\UnreadableDocument;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\MinimalPdf;

final class DocumentTextTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    #[Test]
    public function a_pdf_with_a_text_layer_is_read_line_by_line(): void
    {
        $path = $this->file('pdf', MinimalPdf::withLines(['K10/110  Metal stud partition    312  m2', 'M50/010  Carpet tiles 500x500   690  m2']));

        $text = DocumentText::fromFile($path, 'pdf');

        $this->assertStringContainsString("Metal stud partition    312  m2\n", $text);
        $this->assertStringContainsString('Carpet tiles 500x500   690  m2', $text);
    }

    #[Test]
    public function a_pdf_without_text_is_refused_rather_than_guessed_at(): void
    {
        $path = $this->file('pdf', MinimalPdf::withLines([]));

        $this->expectException(UnreadableDocument::class);
        $this->expectExceptionMessage('looks scanned');

        DocumentText::fromFile($path, 'pdf');
    }

    #[Test]
    public function a_spreadsheet_row_becomes_a_line_the_grounding_check_accepts(): void
    {
        $path = $this->file('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Ref', 'Description', 'Qty', 'Unit']));
        // Column C left empty on purpose: holes must not shift Qty away from Unit.
        $writer->addRow(new Row([0 => Cell::fromValue('K10/110'), 1 => Cell::fromValue('Metal stud partition, 70mm'), 3 => Cell::fromValue(312.0), 4 => Cell::fromValue('m2')]));
        $writer->close();

        $text = DocumentText::fromFile($path, 'xlsx');
        $line = "K10/110\tMetal stud partition, 70mm\t\t312\tm2";

        $this->assertStringContainsString("Sheet: Sheet1\nRef\tDescription\tQty\tUnit\n{$line}\n", $text);
        $this->assertSame([], (new GroundingValidator)->problems(
            ['trade' => 'partitions', 'description' => 'Metal stud partition', 'quantity' => 312, 'unit' => 'm2', 'spec_reference' => 'K10/110', 'source_text' => $line],
            $text,
        ));
    }

    #[Test]
    public function a_csv_is_read_like_a_spreadsheet(): void
    {
        $path = $this->file('csv', "Description,Qty,Unit\nCarpet tiles to open plan,640,m2\n");

        $this->assertStringContainsString("Carpet tiles to open plan\t640\tm2", DocumentText::fromFile($path, 'csv'));
    }

    private function file(string $extension, ?string $contents = null): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('doc-', true).'.'.$extension;
        if ($contents !== null) {
            file_put_contents($path, $contents);
        }
        $this->files[] = $path;

        return $path;
    }
}
