<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseEnrollment extends Model
{
    public const StatusActive = 'active';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'term_offering_id',
        'status',
        'units_snapshot',
        'added_at',
        'dropped_at',
        'withdrawn_at',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_snapshot' => 'decimal:2',
            'added_at' => 'datetime',
            'dropped_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<TermOffering, $this> */
    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
    }

    /** @return HasMany<EnrollmentSeatReservation, $this> */
    public function seatReservations(): HasMany
    {
        return $this->hasMany(EnrollmentSeatReservation::class);
    }

    /** @return HasOne<GradeRosterRow, $this> */
    public function gradeRosterRow(): HasOne
    {
        return $this->hasOne(GradeRosterRow::class);
    }

    /** @return HasMany<StudentScheduleBinding, $this> */
    public function scheduleBindings(): HasMany
    {
        return $this->hasMany(StudentScheduleBinding::class);
    }
}
