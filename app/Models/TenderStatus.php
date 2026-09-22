<?php

declare(strict_types=1);

namespace App\Models;

enum TenderStatus: string
{
    /** An uploaded file is being turned into text (PDF layout, OCR, spreadsheet). */
    case Reading = 'reading';
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Extracted = 'extracted';
    case Failed = 'failed';
}
