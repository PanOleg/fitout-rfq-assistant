<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property ?Carbon $last_used_at
 * @property ?Carbon $revoked_at
 * @property-read User $user
 */
class AccessToken extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Creates a token for $user and returns it with the plain value, which is
     * shown once and never stored.
     *
     * @return array{self, string}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = 'rfq_'.Str::random(40);
        $token = self::query()->create(['user_id' => $user->id, 'name' => $name, 'token_hash' => self::hash($plain)]);

        return [$token, $plain];
    }

    /** The active token with this plain value, if any. */
    public static function findActive(string $plain): ?self
    {
        return self::query()->with('user')->where('token_hash', self::hash($plain))->whereNull('revoked_at')->first();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
