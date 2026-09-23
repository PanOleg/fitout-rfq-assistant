<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Evals\EvalCaseDrafter;
use App\Models\Tender;
use App\Models\TenderReview;
use App\Models\TenderStatus;
use FitOut\Domain\LineItem;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;
use FitOut\Extraction\GroundingValidator;
use FitOut\Extraction\Violation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Where a person settles what the tool was unsure of: keep, correct or drop
 * each accepted item, fix or discard each rejected one, add what was missed.
 *
 * The reviewer is the authority on trade and quantity — a quantity they
 * calculate is theirs to calculate. The quote is still required and must be
 * in the document, so every item stays traceable. Saving also writes an eval
 * case draft whose expectations are these decisions: the evals grow from the
 * tool's real mistakes, not only from imagined ones.
 */
final class ReviewController
{
    private const BLANK_ROWS = 3;

    public function show(Tender $tender): View
    {
        abort_unless($tender->status === TenderStatus::Extracted, 409, "Tender is {$tender->status->value}; it can be reviewed once extraction has finished.");
        $result = $tender->extractionResult();

        $rows = $tender->review !== null
            ? array_map(static fn (array $i): array => ['origin' => 'reviewed', 'decision' => 'keep', 'problems' => []] + $i, $tender->review['items'])
            : [
                ...array_map(static fn (LineItem $i): array => ['origin' => 'accepted', 'decision' => 'keep', 'problems' => []] + $i->toArray(), $result->items ?? []),
                ...array_map(static fn (Violation $v): array => [
                    'origin' => 'rejected',
                    'decision' => 'drop',
                    'problems' => $v->problems,
                    'trade' => is_string($v->item['trade'] ?? null) ? $v->item['trade'] : '',
                    'description' => is_string($v->item['description'] ?? null) ? $v->item['description'] : '',
                    'quantity' => is_int($v->item['quantity'] ?? null) || is_float($v->item['quantity'] ?? null) ? $v->item['quantity'] : '',
                    'unit' => is_string($v->item['unit'] ?? null) ? $v->item['unit'] : '',
                    'spec_reference' => is_string($v->item['spec_reference'] ?? null) ? $v->item['spec_reference'] : null,
                    'source_text' => is_string($v->item['source_text'] ?? null) ? $v->item['source_text'] : '',
                ], $result->rejected ?? []),
            ];

        for ($i = 0; $i < self::BLANK_ROWS; $i++) {
            $rows[] = ['origin' => 'added', 'decision' => 'drop', 'problems' => [], 'trade' => '', 'description' => '', 'quantity' => '', 'unit' => '', 'spec_reference' => null, 'source_text' => ''];
        }

        return view('review', [
            'tender' => $tender,
            'history' => $tender->reviews()->get(),
            'reviewer' => request()->getUser(),
            'rows' => $rows,
            'warnings' => [
                ...($tender->read_by === 'ocr' ? ['Read by OCR from a scan: check quantities against the original.'] : []),
                ...($result->warnings ?? []),
            ],
            'trades' => Trade::cases(),
            'units' => Unit::values(),
        ]);
    }

    public function update(Request $request, Tender $tender): RedirectResponse
    {
        abort_unless($tender->status === TenderStatus::Extracted && $tender->document !== null, 409);

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.decision' => ['required', Rule::in(['keep', 'drop'])],
            'rows.*.origin' => ['required', Rule::in(['accepted', 'rejected', 'added', 'reviewed'])],
            'rows.*.trade' => ['exclude_unless:rows.*.decision,keep', 'required', Rule::enum(Trade::class)],
            'rows.*.description' => ['exclude_unless:rows.*.decision,keep', 'required', 'string', 'max:300'],
            'rows.*.quantity' => ['exclude_unless:rows.*.decision,keep', 'required', 'numeric', 'gt:0'],
            'rows.*.unit' => ['exclude_unless:rows.*.decision,keep', 'required', Rule::in(Unit::values())],
            'rows.*.spec_reference' => ['exclude_unless:rows.*.decision,keep', 'nullable', 'string', 'max:50'],
            'rows.*.source_text' => ['exclude_unless:rows.*.decision,keep', 'required', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // The access token is shared, so the name is how a decision gets an author.
            'reviewer' => ['required', 'string', 'max:100'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        // The rules above guarantee every field of a kept row; a dropped row carries only its decision.
        /** @var array<int, array{decision: 'drop', origin: string}|array{decision: 'keep', origin: string, trade: string, description: string, quantity: numeric-string|int|float, unit: string, spec_reference?: ?string, source_text: string}> $rows */
        $rows = $data['rows'];
        $items = [];
        $changes = ['kept' => 0, 'dropped' => 0, 'recovered' => 0, 'added' => 0];
        foreach ($rows as $index => $row) {
            if ($row['decision'] !== 'keep') {
                $changes['dropped'] += $row['origin'] === 'accepted' ? 1 : 0;

                continue;
            }
            if (! GroundingValidator::isQuoted($row['source_text'], $tender->document)) {
                throw ValidationException::withMessages(["rows.{$index}.source_text" => 'The quote must be copied from the document, so the item stays traceable.']);
            }
            $item = [
                'trade' => $row['trade'],
                'description' => trim($row['description']),
                'quantity' => (float) $row['quantity'],
                'unit' => $row['unit'],
                'spec_reference' => ($row['spec_reference'] ?? '') === '' ? null : $row['spec_reference'],
                'source_text' => trim($row['source_text']),
            ];
            $items[] = $item;
            match ($row['origin']) {
                'rejected' => $changes['recovered']++,
                'added' => $changes['added']++,
                default => $changes['kept']++,
            };
        }

        $summary = sprintf('%d kept, %d dropped, %d recovered from rejected, %d added.', $changes['kept'], $changes['dropped'], $changes['recovered'], $changes['added']);
        $notes = (string) ($data['notes'] ?? '');

        // Optimistic lock: the form carries the version it was built from. If someone saved
        // since, this save is refused instead of silently replacing their decisions.
        $saved = DB::transaction(function () use ($tender, $data, $items, $notes, $summary): ?int {
            $current = Tender::query()->whereKey($tender->id)->lockForUpdate()->value('review_version');
            if ((int) $current !== (int) $data['version']) {
                return null;
            }
            $version = (int) $current + 1;
            TenderReview::query()->create([
                'tender_id' => $tender->id, 'version' => $version, 'reviewer' => $data['reviewer'],
                'items' => $items, 'notes' => $notes === '' ? null : $notes, 'summary' => $summary, 'created_at' => now(),
            ]);
            $tender->update(['review' => ['items' => $items, 'notes' => $notes], 'reviewed_at' => now(), 'review_version' => $version]);

            return $version;
        });

        if ($saved === null) {
            $latest = $tender->reviews()->first();

            return redirect()->route('tenders.review', $tender)->withInput()->withErrors([
                'version' => sprintf('Not saved: %s saved a newer review (version %d) at %s. Reload to see it, then apply your changes again.', $latest->reviewer ?? 'someone', $latest->version ?? 0, $latest?->created_at->toDayDateTimeString() ?? 'just now'),
            ]);
        }

        $draft = (new EvalCaseDrafter((string) config('rfq.evals.drafts_dir')))->draft(
            $tender,
            'review-'.substr(strtolower($tender->id), -8),
            array_map(LineItem::fromArray(...), $items),
            $summary.(($data['notes'] ?? '') === '' ? '' : "\n\n".$data['notes']),
        );

        return redirect()->route('tenders.review', $tender)->with('status', "Review version {$saved} saved by {$data['reviewer']}: {$summary} Eval case draft {$draft} written — anonymise it before moving it to evals/cases.");
    }
}
