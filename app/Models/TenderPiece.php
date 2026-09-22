<?php

declare(strict_types=1);

namespace App\Models;

use FitOut\Extraction\ExtractionResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $tender_id
 * @property int $position
 * @property int $offset
 * @property int $length
 * @property string $status pending|done|failed
 * @property ?array<string, mixed> $result
 * @property ?string $failure
 */
class TenderPiece extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function extractionResult(): ?ExtractionResult
    {
        return $this->result === null ? null : ExtractionResult::fromArray($this->result);
    }
}
