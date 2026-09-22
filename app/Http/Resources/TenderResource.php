<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tender;
use FitOut\Domain\LineItem;
use FitOut\Domain\WorkPackage;
use FitOut\Extraction\Violation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tender */
final class TenderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource->extractionResult();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'return_by' => $this->return_by->toDateString(),
            'source_filename' => $this->source_filename,
            'status' => $this->status->value,
            'failure' => $this->failure,
            'packages' => $result === null ? null : array_map(static fn (WorkPackage $p): array => [
                'trade' => $p->trade->value,
                'label' => $p->trade->label(),
                'items' => array_map(static fn (LineItem $i): array => $i->toArray(), $p->items),
            ], WorkPackage::groupByTrade($result->items)),
            'needs_review' => $result === null ? null : [
                'warnings' => $result->warnings,
                'rejected' => array_map(static fn (Violation $v): array => $v->toArray(), $result->rejected),
            ],
            'extraction' => $result === null ? null : [
                'model' => $result->model,
                'prompt_version' => $result->promptVersion,
                'attempts' => $result->attempts,
                'from_cache' => $result->fromCache,
                'usage' => $result->usage->toArray(),
                'cost_usd' => $result->costUsd(),
                'duration_ms' => $result->durationMs,
            ],
            'links' => [
                'self' => route('tenders.show', $this->id),
                'rfqs' => route('tenders.rfqs', $this->id),
            ],
        ];
    }
}
