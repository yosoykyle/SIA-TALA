<?php

namespace App\Models;

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
