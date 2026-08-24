<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<int, string> $staff_roles
 * @property Carbon $expires_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 */
class StaffInvitation extends Model
{
    /** @var list<string> */
    protected $hidden = ['token_digest'];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'invited_by',
        'superseded_by_id',
        'email',
        'staff_roles',
        'token_digest',
        'reason',
        'authority',
        'evidence_reference',
        'expires_at',
        'sent_at',
        'accepted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'staff_roles' => 'array',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<StaffInvitation, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
