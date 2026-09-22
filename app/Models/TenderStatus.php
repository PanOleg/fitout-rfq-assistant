<?php

declare(strict_types=1);

namespace App\Models;

enum TenderStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Extracted = 'extracted';
    case Failed = 'failed';
}
