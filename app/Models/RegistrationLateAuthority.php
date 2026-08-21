<?php

namespace App\Models;

use Database\Factories\RegistrationLateAuthorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $authority_date
 * @property Carbon $effective_at
 * @property Carbon $recorded_at
 * @property Carbon|null $consumed_at
 */
class RegistrationLateAuthority extends Model
{
    /** @use HasFactory<RegistrationLateAuthorityFactory> */
    use HasFactory;

    public const ActionAdjustment = 'Adjustment';

    public const ActionCourseDrop = 'CourseDrop';

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id', 'term_id', 'action_type', 'before_course_enrollment_id',
        'after_section_id', 'approving_office', 'authority_reference', 'authority_date',
        'reason', 'effective_at', 'learner_acknowledgement_basis',
        'source_academic_decision', 'recorded_by', 'recorded_at', 'consumed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'authority_date' => 'date',
            'effective_at' => 'datetime',
            'recorded_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function beforeCourse(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'before_course_enrollment_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function afterSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'after_section_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
