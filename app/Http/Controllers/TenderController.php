<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenderRequest;
use App\Http\Resources\TenderResource;
use App\Jobs\ExtractTender;
use App\Models\Tender;
use App\Models\TenderStatus;
use FitOut\Domain\WorkPackage;
use FitOut\Rfq\RfqComposer;
use FitOut\Rfq\RfqDraft;
use FitOut\Suppliers\SupplierMatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

final class TenderController
{
    /**
     * Idempotent on the Idempotency-Key header: a client retrying a timed-out
     * upload gets the tender it already created, not a second extraction bill.
     */
    public function store(StoreTenderRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if ($key !== null && ($existing = Tender::query()->where('idempotency_key', $key)->first()) !== null) {
            return (new TenderResource($existing))->response()->setStatusCode(200);
        }

        try {
            $tender = Tender::query()->create([
                ...$request->safe()->only(['name', 'region', 'return_by', 'document']),
                'idempotency_key' => $key,
                'status' => TenderStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent requests with the same key: the other one won.
            return (new TenderResource(Tender::query()->where('idempotency_key', $key)->firstOrFail()))->response()->setStatusCode(200);
        }

        ExtractTender::dispatch($tender->id);

        return (new TenderResource($tender->refresh()))->response()->setStatusCode(202);
    }

    public function show(Tender $tender): TenderResource
    {
        return new TenderResource($tender);
    }

    public function rfqs(Tender $tender, SupplierMatcher $matcher, RfqComposer $composer): JsonResponse
    {
        $result = $tender->extractionResult();
        if ($tender->status !== TenderStatus::Extracted || $result === null) {
            return response()->json(['message' => "Tender is {$tender->status->value}; RFQs are available once extraction has finished."], 409);
        }

        $drafts = array_map(
            static fn (WorkPackage $package): RfqDraft => $composer->compose($tender->name, $tender->return_by, $matcher->shortlist($package, $tender->region)),
            WorkPackage::groupByTrade($result->items),
        );

        return response()->json(['data' => array_map(static fn (RfqDraft $d): array => $d->toArray(), $drafts)]);
    }
}
