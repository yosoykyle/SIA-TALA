<?php

namespace App\Models;

use Database\Factories\TermCalendarWindowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $opens_on
 * @property Carbon $closes_on
 */
class TermCalendarWindow extends Model
{
    /** @use HasFactory<TermCalendarWindowFactory> */
    use HasFactory;

    public const TypeEnrollment = 'Enrollment';

    public const TypeLateEnrollment = 'LateEnrollment';

    public const TypeEnrollmentAdjustment = 'EnrollmentAdjustment';

    public const TypeCourseDrop = 'CourseDrop';

    public const TypeExaminationPeriod = 'ExaminationPeriod';

    public const TypeGradeEntry = 'GradeEntry';

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            self::TypeEnrollment => 'Enrollment',
            self::TypeLateEnrollment => 'Late Enrollment',
            self::TypeEnrollmentAdjustment => 'Enrollment Adjustment',
            self::TypeCourseDrop => 'Course Drop',
            self::TypeExaminationPeriod => 'Examination Period',
            self::TypeGradeEntry => 'Grade Entry',
        ];
    }

    protected $fillable = ['term_calendar_package_id', 'window_type', 'opens_on', 'closes_on', 'cutoff_at'];

    protected function casts(): array
    {
        return ['opens_on' => 'date', 'closes_on' => 'date'];
    }

    /** @return BelongsTo<TermCalendarPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TermCalendarPackage::class, 'term_calendar_package_id');
    }
}
