<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tender;
use App\Models\TenderStatus;
use FitOut\Extraction\LineItemExtractor;
use FitOut\Llm\Exceptions\LlmOutputTruncated;
use FitOut\Llm\Exceptions\LlmRefused;
use FitOut\Llm\ModelPricing;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs extraction off the request path. Transient model errors (LlmUnavailable)
 * are thrown so the queue retries with backoff; errors a retry cannot fix mark
 * the tender failed straight away instead of burning four more calls.
 */
final class ExtractTender implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly string $tenderId) {}

    public function uniqueId(): string
    {
        return $this->tenderId;
    }

    public function handle(LineItemExtractor $extractor): void
    {
        $tender = Tender::query()->find($this->tenderId);
        if ($tender === null || $tender->status === TenderStatus::Extracted) {
            return; // deleted, or a duplicate delivery after success
        }

        $tender->update(['status' => TenderStatus::Extracting]);

        try {
            $result = $extractor->extract($tender->document);
        } catch (LlmOutputTruncated $e) {
            $cost = ModelPricing::costUsd($e->model, $e->usage);
            $tender->update(['status' => TenderStatus::Failed, 'failure' => $e->getMessage().($cost === null ? '' : sprintf(' ($%.4f spent)', $cost))]);

            return;
        } catch (LlmRefused $e) {
            $tender->update(['status' => TenderStatus::Failed, 'failure' => $e->getMessage()]);

            return;
        }

        $tender->update(['status' => TenderStatus::Extracted, 'extraction' => $result->toArray(), 'failure' => null]);
    }

    public function failed(Throwable $e): void
    {
        Tender::query()->whereKey($this->tenderId)->update([
            'status' => TenderStatus::Failed,
            'failure' => 'Extraction did not complete: '.$e->getMessage(),
        ]);
    }
}
