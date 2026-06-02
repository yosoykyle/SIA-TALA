<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class EnrollmentSubject extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'subject_id',
        'section_meeting_id',
        'units',
        'lec_hours',
        'status',
        'is_dropped',
        'dropped_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'decimal:2',
            'lec_hours' => 'decimal:2',
            'is_dropped' => 'boolean',
            'dropped_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function sectionMeeting(): BelongsTo
    {
        return $this->belongsTo(SectionMeeting::class);
    }

    public function grade(): HasOne
    {
        return $this->hasOne(Grade::class);
    }

    public function scopeAssignedToFaculty(Builder $query, User $faculty): Builder
    {
        return $query->where(function (Builder $query) use ($faculty): void {
            $query->whereHas('sectionMeeting', function (Builder $meetingQuery) use ($faculty): void {
                $meetingQuery->where('faculty_id', $faculty->id);
            })->orWhereExists(function ($sectionTeacherQuery) use ($faculty): void {
                $sectionTeacherQuery
                    ->selectRaw('1')
                    ->from('section_teacher')
                    ->join('enrollments as section_teacher_enrollments', 'section_teacher_enrollments.section_id', '=', 'section_teacher.section_id')
                    ->whereColumn('section_teacher_enrollments.id', 'enrollment_subjects.enrollment_id')
                    ->whereColumn('section_teacher.subject_id', 'enrollment_subjects.subject_id')
                    ->where('section_teacher.user_id', $faculty->id);
            });
        });
    }

    public function isAssignedToFaculty(User $faculty): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->assignedToFaculty($faculty)
            ->exists();
    }

    public function canReceiveFacultyGrade(): bool
    {
        return $this->status === 'enrolled' && ! $this->is_dropped;
    }

    public function usesShsGrading(): bool
    {
        $this->loadMissing('enrollment.studentProfile.program');

        $educationLevel = Str::of((string) $this->enrollment?->studentProfile?->education_level)->lower()->squish()->toString();
        $department = Str::of((string) $this->enrollment?->studentProfile?->program?->department)->lower()->squish()->toString();

        return in_array($educationLevel, ['shs', 'senior high school'], true)
            || in_array($department, ['shs', 'senior high school'], true);
    }
}
