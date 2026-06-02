<?php

namespace App\Models;

use App\Enums\GradeCorrectionStatus;
use Database\Factories\GradeCorrectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class GradeCorrection extends Model
{
    /** @use HasFactory<GradeCorrectionFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'grade_id',
        'subject_id',
        'term_id',
        'assessment_component',
        'current_grade',
        'requested_action',
        'reason',
        'attachment_paths',
        'status',
        'assigned_to',
        'creator_id',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_grade' => 'decimal:2',
            'attachment_paths' => 'array',
            'status' => GradeCorrectionStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeVisibleToFaculty(Builder $query, User $faculty): Builder
    {
        return $query->where(function (Builder $query) use ($faculty): void {
            $query->whereHas('grade', function (Builder $gradeQuery) use ($faculty): void {
                $gradeQuery->where('faculty_id', $faculty->id);
            })->orWhereExists(function ($meetingQuery) use ($faculty): void {
                $meetingQuery
                    ->selectRaw('1')
                    ->from('section_meetings')
                    ->whereColumn('section_meetings.term_id', 'grade_corrections.term_id')
                    ->whereColumn('section_meetings.subject_id', 'grade_corrections.subject_id')
                    ->where('section_meetings.faculty_id', $faculty->id);
            })->orWhereExists(function ($sectionTeacherQuery) use ($faculty): void {
                $sectionTeacherQuery
                    ->selectRaw('1')
                    ->from('section_teacher')
                    ->join('sections', 'sections.id', '=', 'section_teacher.section_id')
                    ->whereColumn('sections.term_id', 'grade_corrections.term_id')
                    ->whereColumn('section_teacher.subject_id', 'grade_corrections.subject_id')
                    ->where('section_teacher.user_id', $faculty->id);
            });
        });
    }

    public function isVisibleToFaculty(User $faculty): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->visibleToFaculty($faculty)
            ->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'assigned_to', 'resolved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
