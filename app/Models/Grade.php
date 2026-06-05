<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Grade extends Model
{
    use LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'enrollment_subject_id',
        'subject_id',
        'term_id',
        'faculty_id',
        'prelim_grade',
        'midterm_grade',
        'final_grade',
        'grade',
        'remarks',
        'is_inc',
        'inc_expires_at',
        'is_finalized',
        'finalized_by',
        'finalized_at',
        'reopened_by',
        'reopened_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prelim_grade' => 'decimal:2',
            'midterm_grade' => 'decimal:2',
            'final_grade' => 'decimal:2',
            'grade' => 'decimal:2',
            'is_inc' => 'boolean',
            'inc_expires_at' => 'datetime',
            'is_finalized' => 'boolean',
            'finalized_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function enrollmentSubject(): BelongsTo
    {
        return $this->belongsTo(EnrollmentSubject::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function usesShsGrading(): bool
    {
        $this->loadMissing([
            'enrollment.studentProfile.program',
            'enrollmentSubject.enrollment.studentProfile.program',
        ]);

        if ($this->enrollmentSubject instanceof EnrollmentSubject) {
            return $this->enrollmentSubject->usesShsGrading();
        }

        $studentProfile = $this->enrollment?->studentProfile;
        $educationLevel = Str::of((string) $studentProfile?->education_level)->lower()->squish()->toString();
        $department = Str::of((string) $studentProfile?->program?->department)->lower()->squish()->toString();

        return in_array($educationLevel, ['shs', 'senior high school'], true)
            || in_array($department, ['shs', 'senior high school'], true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['grade', 'remarks', 'is_finalized', 'is_inc'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
