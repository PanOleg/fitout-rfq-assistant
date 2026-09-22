<?php

declare(strict_types=1);

namespace FitOut\Ingestion;

/**
 * Turns an uploaded bill into plain text, deterministically.
 *
 * The rest of the pipeline stays text-in: the model quotes the text, and the
 * grounding check verifies each quote against it. Sending a PDF to the model
 * as a document block would lose that check — and citations, the API's own
 * grounding, cannot be combined with structured outputs.
 */
interface DocumentReader
{
    public const FORMATS = ['pdf', 'xlsx', 'csv', 'txt'];

    /**
     * @param  'pdf'|'xlsx'|'csv'|'txt'  $format
     *
     * @throws UnreadableDocument with a reason a person can act on
     */
    public function read(string $path, string $format): ReadDocument;
}
