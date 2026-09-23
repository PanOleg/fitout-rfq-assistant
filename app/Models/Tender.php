<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use FitOut\Domain\LineItem;
use FitOut\Extraction\ExtractionResult;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $idempotency_key
 * @property ?string $request_fingerprint
 * @property string $name
 * @property string $region
 * @property CarbonImmutable $return_by
 * @property ?string $document
 * @property ?string $source_filename
 * @property ?string $read_by
 * @property ?string $source_path
 * @property ?string $batch_id
 * @property TenderStatus $status
 * @property ?array<string, mixed> $extraction
 * @property ?string $failure
 * @property ?array{items: list<array{trade: string, description: string, quantity: float|int, unit: string, spec_reference?: ?string, source_text: string}>, notes?: string} $review
 * @property ?Carbon $reviewed_at
 * @property int $review_version
 * @property Carbon $created_at
 */
class Tender extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['document', 'source_path'];

    protected function casts(): array
    {
        return [
            'return_by' => 'immutable_date',
            'status' => TenderStatus::class,
            'extraction' => 'array',
            'review' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return HasMany<TenderReview, $this> newest first */
    public function reviews(): HasMany
    {
        return $this->hasMany(TenderReview::class)->orderByDesc('version');
    }

    /** @return HasMany<TenderPiece, $this> */
    public function pieces(): HasMany
    {
        return $this->hasMany(TenderPiece::class)->orderBy('position');
    }

    /**
     * What goes into packages and RFQs: the reviewer's items once the tender
     * has been reviewed, the extraction's accepted items before that.
     *
     * @return list<LineItem>
     */
    public function finalItems(): array
    {
        if ($this->review !== null) {
            return array_map(LineItem::fromArray(...), $this->review['items']);
        }

        return $this->extractionResult()->items ?? [];
    }

    public function extractionResult(): ?ExtractionResult
    {
        return $this->extraction === null ? null : ExtractionResult::fromArray($this->extraction);
    }
}
