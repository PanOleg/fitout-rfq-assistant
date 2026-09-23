<?php

declare(strict_types=1);

namespace Tests\Support;

/** Builds small one-page PDFs — text, tables, scans — so tests need no binary fixtures. */
final class MinimalPdf
{
    /** @param list<string> $lines */
    public static function withLines(array $lines): string
    {
        $text = "BT /F1 10 Tf 12 TL 40 800 Td\n";
        foreach ($lines as $line) {
            $text .= '('.self::escape($line).") Tj T*\n";
        }

        return self::page($text.'ET');
    }

    /**
     * A table drawn column by column — every description first, then every
     * quantity — the way many BoQ exporters write PDFs. Rows only line up if
     * the reader places text by position.
     *
     * @param  list<array{string, string}>  $rows  [description, quantity]
     */
    public static function tableDrawnByColumn(array $rows): string
    {
        $text = '';
        foreach ([0 => 40, 1 => 400] as $column => $x) {
            foreach ($rows as $i => $row) {
                $text .= 'BT /F1 10 Tf '.$x.' '.(800 - 14 * $i).' Td ('.self::escape($row[$column]).") Tj ET\n";
            }
        }

        return self::page($text);
    }

    /**
     * An image-only PDF, like a scan: the lines are rasterised, so there is no
     * text layer and only OCR can read them.
     *
     * @param  list<string>  $lines
     */
    public static function scanOf(array $lines, int $scale = 4): string
    {
        $font = 5;
        $w = (imagefontwidth($font) * max(array_map(strlen(...), $lines)) + 40) * $scale;
        $h = (imagefontheight($font) * 2 * count($lines) + 40) * $scale;

        $small = imagecreatetruecolor(intdiv($w, $scale), intdiv($h, $scale));
        imagefill($small, 0, 0, (int) imagecolorallocate($small, 255, 255, 255));
        foreach ($lines as $i => $line) {
            imagestring($small, $font, 20, 20 + $i * imagefontheight($font) * 2, $line, (int) imagecolorallocate($small, 0, 0, 0));
        }
        $image = imagescale($small, $w, $h, IMG_NEAREST_NEIGHBOUR) ?: $small;

        $gray = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $gray .= chr(imagecolorat($image, $x, $y) & 0xFF);
            }
        }

        // Page size such that rendering at 300 dpi gives back the image's own pixels.
        $pw = round($w * 72 / 300, 2);
        $ph = round($h * 72 / 300, 2);
        $data = (string) gzcompress($gray);

        return self::document([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] /Contents 4 0 R /Resources << /XObject << /Im1 5 0 R >> >> >>",
            self::stream("q {$pw} 0 0 {$ph} 0 0 cm /Im1 Do Q"),
            "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ".strlen($data)." >>\nstream\n{$data}\nendstream",
        ]);
    }

    private static function page(string $content): string
    {
        return self::document([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            self::stream($content),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);
    }

    private static function stream(string $content): string
    {
        return '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** @param list<string> $objects */
    private static function document(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
