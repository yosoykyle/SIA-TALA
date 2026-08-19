<?php

namespace App\Models;

use Database\Factories\CourseSpecificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSpecification extends Model
{
    /** @use HasFactory<CourseSpecificationFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateRetired = 'RETIRED';

    public const GradingProfileServitechV1 = 'servitech_v1';

    public const GradingProfileCollegeStandard = 'college_standard';

    public const ModalityFaceToFace = 'FACE_TO_FACE';

    public const ModalityOnline = 'ONLINE';

    public const SchedulingRecurring = 'Recurring';

    public const SchedulingExternallyArranged = 'ExternallyArranged';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_id',
        'revision_code',
        'authority_reference',
        'effective_from',
        'effective_until',
        'title',
        'description',
        'credit_units',
        'grading_profile_key',
        'grading_profile_version',
        'academic_classification',
        'scheduling_treatment',
        'allowed_modalities',
        'same_faculty_default',
        'effective_term_id',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_units' => 'decimal:2',
            'grading_profile_version' => 'integer',
            'allowed_modalities' => 'array',
            'same_faculty_default' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function effectiveTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'effective_term_id');
    }

    /** @return HasMany<CourseComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(CourseComponent::class);
    }

    /** @return HasMany<CourseRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(CourseRequirement::class);
    }

    /** @return HasMany<CurriculumEntry, $this> */
    public function curriculumEntries(): HasMany
    {
        return $this->hasMany(CurriculumEntry::class);
    }

    public function totalWeeklyContactHours(): float
    {
        return (float) $this->components()->sum('weekly_contact_hours');
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateRetired => 'Retired',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function gradingProfileOptions(): array
    {
        return [
            self::GradingProfileServitechV1 => 'Servitech College Standard',
            self::GradingProfileCollegeStandard => 'College Standard',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return [
            self::ModalityFaceToFace => 'Face-to-Face',
            self::ModalityOnline => 'Online',
        ];
    }

    /** @return array<string, string> */
    public static function schedulingTreatmentOptions(): array
    {
        return [
            self::SchedulingRecurring => 'Recurring master-timetable meetings',
            self::SchedulingExternallyArranged => 'Externally arranged — no recurring meeting',
        ];
    }
}
