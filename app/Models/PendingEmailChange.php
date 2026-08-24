<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $superseded_at
 */
class PendingEmailChange extends Model
{
    /** @var list<string> */
    protected $hidden = ['token_digest'];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'new_email',
        'token_digest',
        'expires_at',
        'verified_at',
        'superseded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
