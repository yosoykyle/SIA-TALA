<?php

namespace App\Models;

use Database\Factories\CourseEnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseEnrollment extends Model
{
    /** @use HasFactory<CourseEnrollmentFactory> */
    use HasFactory;

    public const StatusActive = 'active';

    public const StatusDropped = 'dropped';

    public const StatusWithdrawn = 'withdrawn';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'term_offering_id',
        'section_id',
        'registration_proposal_item_id',
        'published_timetable_version_id',
        'supersedes_course_enrollment_id',
        'change_source',
        'effective_from',
        'effective_until',
        'is_current',
        'proposed_section_id',
        'proposed_at',
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
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_current' => 'boolean',
            'units_snapshot' => 'decimal:2',
            'proposed_at' => 'datetime',
            'added_at' => 'datetime',
            'dropped_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<RegistrationProposalItem, $this> */
    public function proposalItem(): BelongsTo
    {
        return $this->belongsTo(RegistrationProposalItem::class, 'registration_proposal_item_id');
    }

    /** @return BelongsTo<PublishedTimetableVersion, $this> */
    public function publishedTimetableVersion(): BelongsTo
    {
        return $this->belongsTo(PublishedTimetableVersion::class);
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

    /** @return BelongsTo<Section, $this> */
    public function proposedSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'proposed_section_id');
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
