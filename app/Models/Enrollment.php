<?php

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $registered_at
 * @property Carbon|null $officially_enrolled_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $dropped_at
 * @property Carbon|null $withdrawn_at
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'status',
        'student_type',
        'registered_at',
        'officially_enrolled_at',
        'cancelled_at',
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
            'registered_at' => 'datetime',
            'officially_enrolled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'dropped_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function sectionDeliveryGroup(): BelongsTo
    {
        return $this->belongsTo(SectionDeliveryGroup::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Hold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    /** @return HasMany<CourseEnrollment, $this> */
    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function seatReservations(): HasMany
    {
        return $this->hasMany(EnrollmentSeatReservation::class);
    }

    public function gateResults(): HasMany
    {
        return $this->hasMany(EnrollmentGateResult::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(EnrollmentException::class);
    }

    public function lifecycleChanges(): HasMany
    {
        return $this->hasMany(StudentLifecycleChange::class);
    }

    public function displayLabel(): string
    {
        $this->loadMissing('term');

        return collect([
            "#{$this->id}",
            $this->term->label ?? 'No term',
            $this->status,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
