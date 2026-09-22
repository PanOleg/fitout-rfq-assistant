<?php

declare(strict_types=1);

namespace FitOut\Ingestion;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Turns an uploaded bill into plain text, deterministically.
 *
 * The rest of the pipeline stays text-in: the model quotes the text, and the
 * grounding check verifies each quote against it. Sending a PDF to the model
 * as a document block would lose that check — and citations, the API's own
 * grounding, cannot be combined with structured outputs.
 *
 * Spreadsheets become one tab-separated line per row, so "Qty" and "Unit"
 * columns sit next to each other the way the grounding check expects.
 */
final class DocumentText
{
    public const FORMATS = ['pdf', 'xlsx', 'csv', 'txt'];

    /** @param 'pdf'|'xlsx'|'csv'|'txt' $format */
    public static function fromFile(string $path, string $format): string
    {
        $text = match ($format) {
            'pdf' => self::pdf($path),
            'xlsx' => self::spreadsheet(new XlsxReader, $path),
            'csv' => self::spreadsheet(new CsvReader, $path),
            'txt' => (string) file_get_contents($path),
        };

        if (mb_strlen((string) preg_replace('/\s+/u', '', $text)) < 20) {
            throw new UnreadableDocument($format === 'pdf'
                ? 'The PDF has no text layer — it looks scanned. Scanned documents need OCR, which is not supported yet.'
                : 'The file contains no readable text.');
        }

        return $text;
    }

    private static function pdf(string $path): string
    {
        try {
            $pages = (new Parser)->parseFile($path)->getPages();
        } catch (Throwable $e) {
            throw new UnreadableDocument('The PDF could not be read: '.$e->getMessage(), previous: $e);
        }

        return implode("\n\n", array_map(static fn ($page): string => rtrim($page->getText()), $pages))."\n";
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
