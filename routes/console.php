<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Moves on tenders whose jobs were lost (killed worker, flushed queue); see RecoverStuckTenders.
Schedule::command('rfq:recover-stuck')->everyTenMinutes()->withoutOverlapping();
