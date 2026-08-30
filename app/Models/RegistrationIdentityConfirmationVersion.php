<?php

namespace App\Models;

use Database\Factories\RegistrationIdentityConfirmationVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property array<string, int|string|null> $identity_snapshot
 */
class RegistrationIdentityConfirmationVersion extends Model
{
    /** @use HasFactory<RegistrationIdentityConfirmationVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id', 'supersedes_version_id', 'version', 'admission_application_id',
        'source_version', 'source_hash', 'identity_snapshot', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'identity_snapshot' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<self, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_version_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Registration identity confirmations are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Registration identity confirmations cannot be deleted.'));
    }
}
