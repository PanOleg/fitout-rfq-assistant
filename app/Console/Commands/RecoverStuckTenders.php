<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AssembleTender;
use App\Jobs\ExtractTender;
use App\Models\Tender;
use App\Models\TenderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

/**
 * Finds tenders a lost job left behind and moves them on. A worker killed
 * at the wrong moment, a batch callback that never ran, a queue flushed by
 * hand — without this, the tender stays "extracting" forever and nobody is
 * told. Scheduled every ten minutes; safe to run at any time, since every
 * job it dispatches checks the tender's state before doing anything.
 */
final class RecoverStuckTenders extends Command
{
    protected $signature = 'rfq:recover-stuck';

    protected $description = 'Re-assemble, restart or fail tenders whose jobs were lost';

    public function handle(): int
    {
        $now = Carbon::now();
        $pieceBudget = (int) config('rfq.queue.piece_timeout') * 4; // tries per piece, plus backoff

        // Every piece finished but the tender was never assembled: assemble it.
        // A batch that is not finished long after its pieces could have: fail it.
        Tender::query()->where('status', TenderStatus::Extracting)->where('updated_at', '<', $now->copy()->subMinutes(5))
            ->each(function (Tender $tender) use ($now, $pieceBudget): void {
                $batch = $tender->batch_id === null ? null : Bus::findBatch($tender->batch_id);
                if ($batch === null || $batch->finished()) {
                    $this->line("assemble {$tender->id}");
                    AssembleTender::dispatch($tender->id);
                } elseif ($tender->updated_at?->lt($now->copy()->subSeconds(3 * $pieceBudget + 3600)) === true) {
                    $this->line("fail {$tender->id}");
                    $tender->update(['status' => TenderStatus::Failed, 'failure' => 'Extraction stalled: its pieces stopped making progress.']);
                }
            });

        // Waiting to be split and nothing picked it up: dispatch again (the job is unique).
        Tender::query()->where('status', TenderStatus::Pending)->where('updated_at', '<', $now->copy()->subMinutes(30))
            ->each(function (Tender $tender): void {
                $this->line("restart {$tender->id}");
                ExtractTender::dispatch($tender->id);
            });

        // Reading a file for much longer than its job may run: the job is gone.
        Tender::query()->where('status', TenderStatus::Reading)
            ->where('updated_at', '<', $now->copy()->subSeconds(2 * (int) config('rfq.queue.read_timeout') + 600))
            ->update(['status' => TenderStatus::Failed, 'failure' => 'Reading the file stalled. Upload it again.']);

        return self::SUCCESS;
    }
}
