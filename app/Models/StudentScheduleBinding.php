<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentScheduleBinding extends Model
{
    public const SourceRegistrarPlacement = 'registrar_placement';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_enrollment_id',
        'section_meeting_id',
        'is_active',
        'effective_from',
        'effective_until',
        'source',
        'released_by',
        'released_at',
        'release_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<StudentScheduleBinding>  $query
     * @return Builder<StudentScheduleBinding>
     */
    public function scopeActiveOfficial(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereIn(
                'section_meeting_id',
                SectionMeeting::query()->activeOfficial()->select('id'),
            );
    }

    /**
     * @param  Builder<StudentScheduleBinding>  $query
     * @return Builder<StudentScheduleBinding>
     */
    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->whereHas(
            'courseEnrollment.enrollment.studentProfile',
            fn (Builder $query) => $query->where('user_id', $student->id),
        );
    }

    /**
     * @param  Builder<StudentScheduleBinding>  $query
     * @return Builder<StudentScheduleBinding>
     */
    public function scopeForEnrollment(Builder $query, Enrollment $enrollment): Builder
    {
        return $query->whereHas('courseEnrollment', function (Builder $query) use ($enrollment): void {
            $query
                ->where('enrollment_id', $enrollment->id)
                ->where('status', CourseEnrollment::StatusActive);
        });
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /** @return BelongsTo<SectionMeeting, $this> */
    public function sectionMeeting(): BelongsTo
    {
        return $this->belongsTo(SectionMeeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
