<?php

declare(strict_types=1);

namespace FitOut\Extraction;

interface LineItemExtractor
{
    /** Reads a bill of quantities / specification and returns the measured items in it. */
    public function extract(string $document): ExtractionResult;
}
