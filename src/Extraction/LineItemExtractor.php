<?php

declare(strict_types=1);

namespace FitOut\Extraction;

interface LineItemExtractor
{
    /**
     * Reads a bill of quantities / specification and returns the measured items in it.
     *
     * @param  string  $context  lines from earlier in the same file (title, headings, table
     *                           header) that apply to $document when it is one piece of a longer
     *                           file; read for meaning only, never extracted from
     */
    public function extract(string $document, string $context = ''): ExtractionResult;
}
