<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorVerification extends Model
{
    public const StatusValid = 'valid';

    public const StatusSuperseded = 'superseded';

    public const StatusRevoked = 'revoked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'token',
        'status',
        'issued_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusValid => 'Valid',
            self::StatusSuperseded => 'Superseded',
            self::StatusRevoked => 'Revoked',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            'success' => self::StatusValid,
            'warning' => self::StatusSuperseded,
            'danger' => self::StatusRevoked,
        ];
    }

    public function isValid(): bool
    {
        return $this->status === self::StatusValid;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::StatusRevoked;
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
