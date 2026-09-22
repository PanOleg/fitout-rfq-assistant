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
     * The key is bound to the request it was first used with — reusing it for
     * a different document is a client bug and gets a 422, not the old tender.
     */
    public function store(StoreTenderRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        $fields = $request->safe()->only(['name', 'region', 'return_by', 'document']);
        $fingerprint = hash('sha256', (string) json_encode($fields));

        if ($key !== null && ($existing = Tender::query()->where('idempotency_key', $key)->first()) !== null) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $tender = Tender::query()->create([
                ...$fields,
                'idempotency_key' => $key,
                'request_fingerprint' => $key === null ? null : $fingerprint,
                'status' => TenderStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent requests with the same key: the other one won.
            return $this->replay(Tender::query()->where('idempotency_key', $key)->firstOrFail(), $fingerprint);
        }

        ExtractTender::dispatch($tender->id);

        return (new TenderResource($tender->refresh()))->response()->setStatusCode(202);
    }

    private function replay(Tender $existing, string $fingerprint): JsonResponse
    {
        if ($existing->request_fingerprint !== null && ! hash_equals($existing->request_fingerprint, $fingerprint)) {
            return response()->json(['message' => 'This Idempotency-Key was already used with a different request. Use a new key for a new tender.'], 422);
        }

        return (new TenderResource($existing))->response()->setStatusCode(200);
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
