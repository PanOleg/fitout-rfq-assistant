<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tender;
use App\Models\TenderPiece;
use FitOut\Extraction\ChunkedLineItemExtractor;
use FitOut\Llm\Exceptions\LlmOutputTruncated;
use FitOut\Llm\Exceptions\LlmRefused;
use FitOut\Llm\ModelPricing;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Extracts one piece of a tender. Transient model errors (LlmUnavailable) are
 * thrown so the queue retries this piece alone with backoff; errors a retry
 * cannot fix mark the piece failed straight away. The model call is cached per
 * piece, so a retry after a crash does not pay again for what was answered.
 */
final class ExtractTenderPiece implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /** Real exceptions allowed before the piece fails; waiting on the rate limiter does not count. */
    public int $maxExceptions = 6;

    /** @var list<int> */
    public array $backoff = [15, 60, 180, 300];

    public int $timeout;

    public function __construct(public readonly string $tenderId, public readonly int $position)
    {
        $this->timeout = (int) config('rfq.queue.piece_timeout');
        $this->onQueue(config('rfq.queue.extraction'));
    }

    /**
     * Pieces of every tender share one limiter, so a 200-piece bill queues its
     * model calls instead of firing them at once and failing on 429.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('llm')];
    }

    /** Transient errors are retried with backoff for this long, however many tries that takes. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('rfq.queue.piece_retry_minutes'));
    }

    public function handle(ChunkedLineItemExtractor $extractor): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $tender = Tender::query()->find($this->tenderId);
        $piece = TenderPiece::query()->where('tender_id', $this->tenderId)->where('position', $this->position)->first();
        if ($tender === null || $piece === null || $piece->status === TenderPiece::DONE || $tender->document === null) {
            return;
        }

        try {
            $result = $extractor->extractRange($tender->document, $piece->offset, $piece->length);
        } catch (LlmOutputTruncated $e) {
            $cost = ModelPricing::costUsd($e->model, $e->usage);
            $this->fail($piece, $e->getMessage().($cost === null ? '' : sprintf(' ($%.4f spent)', $cost)));

            return;
        } catch (LlmRefused $e) {
            $this->fail($piece, $e->getMessage());

            return;
        }

        $piece->update(['status' => TenderPiece::DONE, 'result' => $result->toArray(), 'failure' => null]);
    }

    public function failed(Throwable $e): void
    {
        TenderPiece::query()->where('tender_id', $this->tenderId)->where('position', $this->position)
            ->update(['status' => TenderPiece::FAILED, 'failure' => 'Extraction did not complete: '.$e->getMessage()]);
    }

    private function fail(TenderPiece $piece, string $reason): void
    {
        $piece->update(['status' => TenderPiece::FAILED, 'failure' => $reason]);
    }
}
