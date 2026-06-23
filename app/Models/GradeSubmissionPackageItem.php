<?php

namespace App\Models;

use Database\Factories\GradeSubmissionPackageItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeSubmissionPackageItem extends Model
{
    /** @use HasFactory<GradeSubmissionPackageItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade_submission_package_id',
        'enrollment_subject_id',
        'grade_id',
        'enrollment_id',
        'student_profile_id',
        'subject_id',
        'entered_values',
        'derived_grade',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_values' => 'array',
            'derived_grade' => 'array',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(GradeSubmissionPackage::class, 'grade_submission_package_id');
    }

    public function enrollmentSubject(): BelongsTo
    {
        return $this->belongsTo(EnrollmentSubject::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
