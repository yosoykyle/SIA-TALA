<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleRevisionEvent extends Model
{
    protected $fillable = [
        'term_id',
        'section_meeting_id',
        'change_type',
        'reason',
        'effective_date',
        'changed_by',
        'old_snapshot_json',
        'new_snapshot_json',
        'affected_student_count',
        'affected_faculty_count',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'old_snapshot_json' => 'array',
            'new_snapshot_json' => 'array',
        ];
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function sectionMeeting()
    {
        return $this->belongsTo(SectionMeeting::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
