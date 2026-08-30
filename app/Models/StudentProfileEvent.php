<?php

namespace App\Models;

use Database\Factories\StudentProfileEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StudentProfileEvent extends Model
{
    /** @use HasFactory<StudentProfileEventFactory> */
    use HasFactory;

    public const TypeCorrection = 'Correction';

    protected $fillable = [
        'student_profile_id', 'event_type', 'source', 'authority_reference', 'reason',
        'before_snapshot', 'after_snapshot', 'changed_fields', 'actor_id', 'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'changed_fields' => 'array',
            'effective_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Student Profile events are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Student Profile events cannot be deleted.'));
    }
}
