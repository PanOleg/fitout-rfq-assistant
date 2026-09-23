<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tender;
use App\Models\TenderPiece;
use App\Models\TenderStatus;
use FitOut\Extraction\ExtractionResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs when every piece of a tender has finished or given up: merges them in
 * document order, or fails the tender naming each piece that failed. A tender
 * is never shown as extracted with a piece silently missing.
 */
final class AssembleTender implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tenderId)
    {
        $this->onQueue(config('rfq.queue.extraction'));
    }

    public function handle(): void
    {
        $tender = Tender::query()->find($this->tenderId);
        if ($tender === null || $tender->status !== TenderStatus::Extracting) {
            return;
        }

        $pieces = $tender->pieces()->get();
        $failed = $pieces->filter(static fn (TenderPiece $p): bool => $p->status !== TenderPiece::DONE);
        if ($pieces->isEmpty() || $failed->isNotEmpty()) {
            $tender->update([
                'status' => TenderStatus::Failed,
                'failure' => $failed->map(static fn (TenderPiece $p): string => sprintf(
                    'Piece %d of %d: %s',
                    $p->position + 1,
                    $pieces->count(),
                    $p->failure ?? 'did not finish.',
                ))->implode(' ') ?: 'The document produced no pieces.',
            ]);

            return;
        }

        /** @var non-empty-list<ExtractionResult> $results */
        $results = $pieces->map(static fn (TenderPiece $p): ExtractionResult => $p->extractionResult() ?? throw new \LogicException("Piece {$p->id} is done without a result."))->values()->all();
        $tender->update(['status' => TenderStatus::Extracted, 'extraction' => ExtractionResult::merge($results)->toArray(), 'failure' => null]);
    }

    public function failed(Throwable $e): void
    {
        Tender::query()->whereKey($this->tenderId)->where('status', TenderStatus::Extracting)->update([
            'status' => TenderStatus::Failed,
            'failure' => 'The pieces could not be assembled: '.$e->getMessage(),
        ]);
    }
}
