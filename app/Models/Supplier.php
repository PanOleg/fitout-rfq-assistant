<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property list<string> $trades
 * @property list<string> $regions
 * @property string $rating
 * @property Carbon $created_at
 */
class Supplier extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trades' => 'array',
            'regions' => 'array',
            'rating' => 'decimal:1',
        ];
    }
}
