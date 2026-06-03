<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionMeeting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'section_id',
        'subject_id',
        'faculty_id',
        'room',
        'day_of_week',
        'starts_at',
        'ends_at',
        'modality',
        'schedule_generation_run_id',
        'committed_by',
        'committed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function dayOptions(): array
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return [
            'on_site' => 'On-site',
            'online' => 'Online',
            'modular' => 'Modular',
            'blended' => 'Blended',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function scheduleGenerationRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class);
    }
}
