<?php

namespace App\Models;

use Database\Factories\RegistrationAdjustmentFinanceConfirmationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $consumed_at
 */
class RegistrationAdjustmentFinanceConfirmation extends Model
{
    /** @use HasFactory<RegistrationAdjustmentFinanceConfirmationFactory> */
    use HasFactory;

    public const EffectNoAdditionalCost = 'NoAdditionalCost';

    protected $fillable = [
        'enrollment_id', 'current_course_enrollment_id', 'replacement_section_id',
        'financial_effect', 'authority_reference', 'confirmed_by', 'confirmed_at',
        'enrollment_adjustment_id', 'consumed_at',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function currentCourseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'current_course_enrollment_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function replacementSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'replacement_section_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<EnrollmentAdjustment, $this> */
    public function enrollmentAdjustment(): BelongsTo
    {
        return $this->belongsTo(EnrollmentAdjustment::class);
    }
}
