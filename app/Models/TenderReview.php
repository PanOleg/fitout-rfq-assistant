<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tender_id
 * @property int $version
 * @property string $reviewer
 * @property list<array<string, mixed>> $items
 * @property ?string $notes
 * @property string $summary
 * @property Carbon $created_at
 */
class TenderReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'created_at' => 'datetime'];
    }
}
