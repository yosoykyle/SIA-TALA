<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicDecision extends Model
{
    public const EffectAllowed = 'ALLOWED';

    public const EffectAdvisingRequired = 'ADVISING_REQUIRED';

    public const EffectBlocked = 'BLOCKED';

    public const EffectPendingDecision = 'PENDING_DECISION';

    protected $fillable = [
        'student_profile_id', 'term_id', 'effect', 'authority_reference', 'authority_date',
        'reason', 'effective_from', 'effective_until', 'state', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'authority_date' => 'date', 'effective_from' => 'date', 'effective_until' => 'date',
            'recorded_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public static function effectOptions(): array
    {
        return [
            self::EffectAllowed => 'Allowed',
            self::EffectAdvisingRequired => 'Advising required',
            self::EffectBlocked => 'Blocked',
            self::EffectPendingDecision => 'Pending decision',
        ];
    }
}
