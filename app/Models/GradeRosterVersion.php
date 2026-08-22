<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeRosterVersion extends Model
{
    public const StateSubmitted = 'SUBMITTED';

    public const StateReturned = 'RETURNED';

    public const StateReleased = 'RELEASED';

    public const StateInvalidated = 'INVALIDATED';

    protected $fillable = [
        'grade_roster_id', 'version_number', 'teaching_assignment_id', 'membership_signature',
        'state', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at',
        'released_by', 'released_at', 'invalidated_at', 'invalidation_reason',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
            'released_at' => 'datetime', 'invalidated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GradeRoster, $this> */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(GradeRoster::class, 'grade_roster_id');
    }

    /** @return BelongsTo<ClassOfferingTeachingAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClassOfferingTeachingAssignment::class, 'teaching_assignment_id');
    }

    /** @return HasMany<GradeRosterVersionRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(GradeRosterVersionRow::class);
    }

    /** @return HasMany<GradeRosterReturnedRow, $this> */
    public function returnedRows(): HasMany
    {
        return $this->hasMany(GradeRosterReturnedRow::class);
    }
}
