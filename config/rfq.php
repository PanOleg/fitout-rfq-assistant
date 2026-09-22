<?php

declare(strict_types=1);

return [
    'llm' => [
        'model' => env('RFQ_LLM_MODEL', 'claude-opus-5'),
        // Extraction is reading, not reasoning: medium holds quality at a lower cost.
        // Re-check with `php artisan rfq:eval` before changing it.
        'effort' => env('RFQ_LLM_EFFORT', 'medium'),
        'max_repairs' => 2,
        // Documents longer than this are read in pieces split between paragraphs.
        // About 5k input tokens: a piece of dense BoQ stays well inside one answer.
        'chunk_chars' => 20_000,
    ],

    'bidders_per_package' => 3,

    'regions' => ['london', 'south-east', 'midlands', 'north-west', 'scotland'],

    'max_document_chars' => 200_000,

    'max_upload_kb' => 20_480,
];
