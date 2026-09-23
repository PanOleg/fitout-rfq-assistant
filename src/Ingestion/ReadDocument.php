<?php

declare(strict_types=1);

namespace FitOut\Ingestion;

/** A file's text and how it was obtained — which decides how far its quotes can be trusted. */
final readonly class ReadDocument
{
    public const TEXT = 'text';

    public const SPREADSHEET = 'spreadsheet';

    /** The PDF's own text, laid out by position (pdftotext -layout). */
    public const PDF_LAYOUT = 'pdf-layout';

    /** The PDF's own text in drawing order (no poppler available). */
    public const PDF_TEXT = 'pdf-text';

    /** Recognised from page images: quotes are checked against a reading, not the file. */
    public const OCR = 'ocr';

    /** @param self::TEXT|self::SPREADSHEET|self::PDF_LAYOUT|self::PDF_TEXT|self::OCR $method */
    public function __construct(
        public string $text,
        public string $method,
    ) {}

    public function isOcr(): bool
    {
        return $this->method === self::OCR;
    }
}
