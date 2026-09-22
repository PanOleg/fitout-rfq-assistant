<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use FitOut\Extraction\ExtractionResult;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $idempotency_key
 * @property ?string $request_fingerprint
 * @property string $name
 * @property string $region
 * @property CarbonImmutable $return_by
 * @property string $document
 * @property ?string $source_filename
 * @property TenderStatus $status
 * @property ?array<string, mixed> $extraction
 * @property ?string $failure
 * @property Carbon $created_at
 */
class Tender extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['document'];

    protected function casts(): array
    {
        return [
            'return_by' => 'immutable_date',
            'status' => TenderStatus::class,
            'extraction' => 'array',
        ];
    }

    public function extractionResult(): ?ExtractionResult
    {
        return $this->extraction === null ? null : ExtractionResult::fromArray($this->extraction);
    }
}
