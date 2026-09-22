<?php

declare(strict_types=1);

namespace FitOut\Ingestion\Local;

use FitOut\Ingestion\DocumentReader;
use FitOut\Ingestion\ReadDocument;
use FitOut\Ingestion\UnreadableDocument;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads files with tools on this machine: poppler and tesseract as external
 * processes, OpenSpout for spreadsheets. Those tools parse untrusted files,
 * so this runs in a queue worker, never in a web request, and each process
 * has a timeout; a page limit caps how much OCR one upload can cause.
 *
 * PDFs: poppler's `pdftotext -layout` places text by position, so a quantity
 * stays on the row it sits on even when the PDF draws cells column by column.
 * Without poppler, smalot/pdfparser reads text in drawing order. A PDF with no
 * text layer is a scan: its pages are rendered (pdftoppm) and recognised
 * (tesseract), and the result is marked as OCR so the tender says so.
 *
 * Spreadsheets become one tab-separated line per row, empty columns kept, so
 * "Qty" and "Unit" stay next to each other the way the grounding check expects.
 */
final readonly class LocalDocumentReader implements DocumentReader
{
    private const SEARCH_PATHS = ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin'];

    private ?string $pdftotext;

    private ?string $pdftoppm;

    private ?string $tesseract;

    /**
     * Binaries are found on PATH (and the usual Homebrew / apt locations)
     * unless given; pass false to force one off.
     */
    public function __construct(
        string|false|null $pdftotext = null,
        string|false|null $pdftoppm = null,
        string|false|null $tesseract = null,
        private int $ocrDpi = 300,
        private int $maxPages = 200,
    ) {
        $finder = new ExecutableFinder;
        $find = static fn (string|false|null $given, string $name): ?string => match (true) {
            $given === false => null,
            is_string($given) => $given,
            default => $finder->find($name, null, self::SEARCH_PATHS),
        };

        $this->pdftotext = $find($pdftotext, 'pdftotext');
        $this->pdftoppm = $find($pdftoppm, 'pdftoppm');
        $this->tesseract = $find($tesseract, 'tesseract');
    }

    public function canOcr(): bool
    {
        return $this->pdftoppm !== null && $this->tesseract !== null;
    }

    /** @param 'pdf'|'xlsx'|'csv'|'txt' $format */
    public function read(string $path, string $format): ReadDocument
    {
        $document = match ($format) {
            'pdf' => $this->pdf($path),
            'xlsx' => new ReadDocument(self::spreadsheet(new XlsxReader, $path), ReadDocument::SPREADSHEET),
            'csv' => new ReadDocument(self::spreadsheet(new CsvReader, $path), ReadDocument::SPREADSHEET),
            'txt' => new ReadDocument((string) file_get_contents($path), ReadDocument::TEXT),
        };

        if (! self::hasText($document->text)) {
            throw new UnreadableDocument($document->isOcr()
                ? 'The PDF looks scanned and no text could be recognised on it.'
                : 'The file contains no readable text.');
        }

        return $document;
    }

    private function pdf(string $path): ReadDocument
    {
        $this->checkPageCount($path);

        $text = $this->pdftotext !== null
            ? new ReadDocument(self::layoutText($this->run([$this->pdftotext, '-layout', '-enc', 'UTF-8', $path, '-'])), ReadDocument::PDF_LAYOUT)
            : new ReadDocument(self::parsedText($path), ReadDocument::PDF_TEXT);

        if (self::hasText($text->text)) {
            return $text;
        }
        if (! $this->canOcr()) {
            throw new UnreadableDocument('The PDF has no text layer — it looks scanned — and OCR is not available on this server.');
        }

        return new ReadDocument($this->ocr($path), ReadDocument::OCR);
    }

    private function checkPageCount(string $path): void
    {
        $pdfinfo = $this->pdftotext === null ? null : dirname($this->pdftotext).'/pdfinfo';
        if ($pdfinfo === null || ! is_executable($pdfinfo)) {
            return;
        }
        if (preg_match('/^Pages:\s+(\d+)/m', $this->run([$pdfinfo, $path]), $m) === 1 && (int) $m[1] > $this->maxPages) {
            throw new UnreadableDocument("The PDF has {$m[1]} pages; the limit is {$this->maxPages}. Split it into sections and upload them as separate tenders.");
        }
    }

    private function ocr(string $path): string
    {
        $dir = sys_get_temp_dir().'/'.uniqid('ocr-', true);
        mkdir($dir);

        try {
            $this->run([(string) $this->pdftoppm, '-r', (string) $this->ocrDpi, '-gray', '-png', $path, "{$dir}/page"]);
            $pages = glob("{$dir}/page-*.png") ?: [];
            sort($pages, SORT_NATURAL);

            // --psm 6 reads the page as one block of rows (tables stay rows);
            // preserve_interword_spaces keeps the gap between Description and Qty.
            return implode("\n\n", array_map(
                fn (string $page): string => rtrim($this->run([(string) $this->tesseract, $page, '-', '--psm', '6', '-c', 'preserve_interword_spaces=1'])),
                $pages,
            ))."\n";
        } finally {
            array_map(unlink(...), glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        $process = new Process($command, timeout: 300);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new UnreadableDocument("The PDF could not be read ({$command[0]}): ".trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    private static function layoutText(string $text): string
    {
        // Pages are separated by form feeds; make them paragraph breaks.
        return str_replace("\f", "\n\n", $text);
    }

    private static function parsedText(string $path): string
    {
        try {
            $pages = (new Parser)->parseFile($path)->getPages();
        } catch (Throwable $e) {
            throw new UnreadableDocument('The PDF could not be read: '.$e->getMessage(), previous: $e);
        }

        return implode("\n\n", array_map(static fn ($page): string => rtrim($page->getText()), $pages))."\n";
    }

    private static function hasText(string $text): bool
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $text)) >= 20;
    }

    private static function spreadsheet(XlsxReader|CsvReader $reader, string $path): string
    {
        try {
            $reader->open($path);
        } catch (Throwable $e) {
            throw new UnreadableDocument('The spreadsheet could not be read: '.$e->getMessage(), previous: $e);
        }

        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $lines = [];
            foreach ($sheet->getRowIterator() as $row) {
                // Cells can have holes (an empty column between Qty and Unit); keep the columns aligned.
                $cells = array_map(static fn (int $i): string => self::cell($row->cells[$i] ?? null), range(0, max(0, $row->getNumCells() - 1)));
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                if ($cells !== []) {
                    $lines[] = implode("\t", $cells);
                }
            }
            if ($lines !== []) {
                $out[] = "Sheet: {$sheet->getName()}\n".implode("\n", $lines);
            }
        }
        $reader->close();

        return implode("\n\n", $out)."\n";
    }

    private static function cell(?Cell $cell): string
    {
        $value = $cell?->getValue();

        return match (true) {
            is_float($value) => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'),
            is_scalar($value) => trim(str_replace(["\r", "\n", "\t"], ' ', (string) $value)),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => '',
        };
    }
}
