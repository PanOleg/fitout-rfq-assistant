<?php

declare(strict_types=1);

return [
    // Shared secret for the API (Bearer) and the review screen (Basic, as password).
    // Unset: open outside production, closed in production.
    'access_token' => env('RFQ_ACCESS_TOKEN'),

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

    // Per-job timeouts. queue.connections.*.retry_after must stay above the longest,
    // or a slow job is handed to a second worker while the first is still on it.
    'queue' => [
        'piece_timeout' => (int) env('RFQ_PIECE_TIMEOUT', 300),  // one piece: up to 3 model calls, halving on overflow
        'read_timeout' => (int) env('RFQ_READ_TIMEOUT', 900),    // OCR of a long scan
    ],

    'bidders_per_package' => 3,

    'regions' => ['london', 'south-east', 'midlands', 'north-west', 'scotland'],

    'max_document_chars' => 200_000,

    'max_upload_kb' => 20_480,

    // Paths to poppler and tesseract; null finds them on PATH. Without pdftotext, PDFs are
    // read in drawing order; without pdftoppm + tesseract, scanned PDFs are refused.
    'ingestion' => [
        'pdftotext' => env('RFQ_PDFTOTEXT'),
        'pdftoppm' => env('RFQ_PDFTOPPM'),
        'tesseract' => env('RFQ_TESSERACT'),
        // Caps how much work one upload can cause (OCR is ~1–3 s a page).
        'max_pages' => 200,
    ],
];
