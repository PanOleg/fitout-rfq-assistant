<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tender;
use App\Models\TenderPiece;
use App\Models\TenderStatus;
use FitOut\Extraction\ChunkedLineItemExtractor;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Splits the tender into pieces and hands each to its own job, in one batch.
 *
 * One job per piece is what lets a 200k-character bill finish: pieces run in
 * parallel on as many workers as there are, each job's timeout covers one
 * piece rather than the whole document, and a retried piece does not redo
 * (or re-pay for) the others. When the batch is done, AssembleTender merges
 * the pieces in document order.
 */
final class ExtractTender implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $tenderId)
    {
        $this->onQueue(config('rfq.queue.extraction'));
    }

    public function uniqueId(): string
    {
        return $this->tenderId;
    }

    public function handle(ChunkedLineItemExtractor $extractor): void
    {
        $tender = Tender::query()->find($this->tenderId);
        if ($tender === null || $tender->status !== TenderStatus::Pending || $tender->document === null) {
            return; // deleted, still being read, or a duplicate delivery
        }

        $tender->pieces()->delete();
        $jobs = [];
        foreach ($extractor->ranges($tender->document) as $position => [$offset, $length]) {
            TenderPiece::query()->create([
                'tender_id' => $tender->id,
                'position' => $position,
                'offset' => $offset,
                'length' => $length,
                'status' => TenderPiece::PENDING,
            ]);
            $jobs[] = new ExtractTenderPiece($tender->id, $position);
        }

        $tender->update(['status' => TenderStatus::Extracting]);
        $tenderId = $tender->id;
        $batch = Bus::batch($jobs)
            ->name("tender {$tenderId}")
            ->onQueue(config('rfq.queue.extraction'))
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($tenderId): void {
                AssembleTender::dispatch($tenderId);
            })
            ->dispatch();

        Tender::query()->whereKey($tenderId)->update(['batch_id' => $batch->id]);
    }

    public function failed(Throwable $e): void
    {
        Tender::query()->whereKey($this->tenderId)->update([
            'status' => TenderStatus::Failed,
            'failure' => 'Extraction could not start: '.$e->getMessage(),
        ]);
    }
}
