<?php

declare(strict_types=1);

namespace Tests\Unit\Ingestion;

use FitOut\Extraction\GroundingValidator;
use FitOut\Ingestion\DocumentReader;
use FitOut\Ingestion\ReadDocument;
use FitOut\Ingestion\UnreadableDocument;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\MinimalPdf;

/**
 * The poppler and tesseract paths run where those tools are installed (CI
 * installs them) and are skipped elsewhere; the fallbacks always run.
 */
final class DocumentReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    #[Test]
    public function a_table_drawn_column_by_column_is_read_back_row_by_row(): void
    {
        if ((new ExecutableFinder)->find('pdftotext', null, ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin']) === null) {
            $this->markTestSkipped('pdftotext (poppler) is not installed.');
        }
        $path = $this->file('pdf', MinimalPdf::tableDrawnByColumn([
            ['M50/010  Carpet tiles to open plan', '640 m2'],
            ['M50/020  Entrance matting, recessed', '12 m2'],
        ]));

        $read = (new DocumentReader)->read($path, 'pdf');

        $this->assertSame(ReadDocument::PDF_LAYOUT, $read->method);
        $this->assertMatchesRegularExpression('/Carpet tiles to open plan\s+640 m2\n/', $read->text);
        $this->assertMatchesRegularExpression('/Entrance matting, recessed\s+12 m2\n/', $read->text);
    }

    #[Test]
    public function without_poppler_a_pdf_is_read_in_drawing_order(): void
    {
        $path = $this->file('pdf', MinimalPdf::withLines(['K10/110  Metal stud partition    312  m2', 'M50/010  Carpet tiles 500x500   690  m2']));

        $read = (new DocumentReader(pdftotext: false))->read($path, 'pdf');

        $this->assertSame(ReadDocument::PDF_TEXT, $read->method);
        $this->assertStringContainsString("Metal stud partition    312  m2\n", $read->text);
    }

    #[Test]
    public function a_scan_is_recognised_and_marked_as_ocr(): void
    {
        $reader = new DocumentReader;
        if (! $reader->canOcr()) {
            $this->markTestSkipped('pdftoppm and tesseract are not installed.');
        }
        $path = $this->file('pdf', MinimalPdf::scanOf(['M50/010 Carpet tiles to open plan   640 m2', 'K40/010 Suspended ceiling, grid     710 m2']));

        $read = $reader->read($path, 'pdf');

        // The test "scan" is a scaled-up bitmap font, harder than a real one: tesseract reads
        // the lines but can slip on a character ("640 m?", "ceiling." for "ceiling,"). That is
        // the reason an OCR'd tender carries a warning to check quantities against the original.
        $this->assertSame(ReadDocument::OCR, $read->method);
        $this->assertMatchesRegularExpression('/Carpet tiles to open plan\s+640\b/', $read->text);
        $this->assertMatchesRegularExpression('/Suspended ceiling\W+grid\s+710\b/', $read->text);
    }

    #[Test]
    public function a_scan_is_refused_with_a_reason_where_ocr_is_not_installed(): void
    {
        $path = $this->file('pdf', MinimalPdf::withLines([]));

        $this->expectException(UnreadableDocument::class);
        $this->expectExceptionMessage('looks scanned — and OCR is not available on this server');

        (new DocumentReader(pdftoppm: false, tesseract: false))->read($path, 'pdf');
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

        $read = (new DocumentReader)->read($path, 'xlsx');
        $line = "K10/110\tMetal stud partition, 70mm\t\t312\tm2";

        $this->assertSame(ReadDocument::SPREADSHEET, $read->method);
        $this->assertStringContainsString("Sheet: Sheet1\nRef\tDescription\tQty\tUnit\n{$line}\n", $read->text);
        $this->assertSame([], (new GroundingValidator)->problems(
            ['trade' => 'partitions', 'description' => 'Metal stud partition', 'quantity' => 312, 'unit' => 'm2', 'spec_reference' => 'K10/110', 'source_text' => $line],
            $read->text,
        ));
    }

    #[Test]
    public function a_csv_is_read_like_a_spreadsheet(): void
    {
        $path = $this->file('csv', "Description,Qty,Unit\nCarpet tiles to open plan,640,m2\n");

        $this->assertStringContainsString("Carpet tiles to open plan\t640\tm2", (new DocumentReader)->read($path, 'csv')->text);
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
