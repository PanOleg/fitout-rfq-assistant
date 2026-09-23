<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenderRequest;
use App\Http\Resources\TenderResource;
use App\Jobs\ExtractTender;
use App\Jobs\ReadTenderDocument;
use App\Models\Tender;
use App\Models\TenderStatus;
use FitOut\Domain\WorkPackage;
use FitOut\Ingestion\ReadDocument;
use FitOut\Rfq\RfqComposer;
use FitOut\Rfq\RfqDraft;
use FitOut\Suppliers\SupplierMatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class TenderController
{
    /**
     * Idempotent on the Idempotency-Key header: a client retrying a timed-out
     * upload gets the tender it already created, not a second extraction bill.
     * The key is bound to the request it was first used with — reusing it for
     * a different document is a client bug and gets a 422, not the old tender.
     *
     * An uploaded file is only stored here; reading it (PDF layout, OCR) is a
     * queued step, so the request returns at once with status "reading".
     */
    public function store(StoreTenderRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        $fields = $request->safe()->only(['name', 'region', 'return_by', 'document']);
        $file = $request->file('file');

        $fingerprint = hash('sha256', json_encode($fields).($file instanceof UploadedFile ? hash_file('sha256', (string) $file->getRealPath()) : ''));

        if ($key !== null && ($existing = Tender::query()->where('idempotency_key', $key)->first()) !== null) {
            return $this->replay($existing, $fingerprint);
        }

        if ($file instanceof UploadedFile) {
            $fields += [
                'status' => TenderStatus::Reading,
                'source_filename' => $file->getClientOriginalName(),
                'source_path' => $file->storeAs('uploads', Str::ulid().'.'.strtolower($file->getClientOriginalExtension()), 'local'),
            ];
        } else {
            $fields += ['status' => TenderStatus::Pending, 'read_by' => ReadDocument::TEXT];
        }

        try {
            $tender = Tender::query()->create([
                ...$fields,
                'idempotency_key' => $key,
                'request_fingerprint' => $key === null ? null : $fingerprint,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent requests with the same key: the other one won.
            return $this->replay(Tender::query()->where('idempotency_key', $key)->firstOrFail(), $fingerprint);
        }

        $tender->status === TenderStatus::Reading ? ReadTenderDocument::dispatch($tender->id) : ExtractTender::dispatch($tender->id);

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
        if ($tender->status !== TenderStatus::Extracted) {
            return response()->json(['message' => "Tender is {$tender->status->value}; RFQs are available once extraction has finished."], 409);
        }

        $drafts = array_map(
            static fn (WorkPackage $package): RfqDraft => $composer->compose($tender->name, $tender->return_by, $matcher->shortlist($package, $tender->region)),
            WorkPackage::groupByTrade($tender->finalItems()),
        );

        return response()->json([
            'data' => array_map(static fn (RfqDraft $d): array => $d->toArray(), $drafts),
            // Drafts before review are built from what the model extracted, unchecked by a person.
            'meta' => $tender->reviewed_at === null
                ? ['reviewed' => false, 'warning' => 'Not reviewed: these drafts use the extracted items as they are. Review the tender before sending them.', 'review' => route('tenders.review', $tender)]
                : ['reviewed' => true, 'reviewed_at' => $tender->reviewed_at->toIso8601String()],
        ]);
    }
}
