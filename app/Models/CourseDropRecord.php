<?php

namespace App\Models;

use Database\Factories\CourseDropRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseDropRecord extends Model
{
    /** @use HasFactory<CourseDropRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id', 'course_enrollment_id', 'authority_reference', 'reason',
        'finance_state', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }
}
