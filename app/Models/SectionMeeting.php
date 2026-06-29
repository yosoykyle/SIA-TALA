<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionMeeting extends Model
{
    public const StateActive = 'active';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'schedule_run_id',
        'scheduling_demand_id',
        'meeting_sequence',
        'faculty_user_id',
        'room_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'modality',
        'state',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meeting_sequence' => 'integer',
            'day_of_week' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<SectionMeeting>  $query
     * @return Builder<SectionMeeting>
     */
    public function scopeActiveOfficial(Builder $query): Builder
    {
        return $query
            ->where('state', self::StateActive)
            ->whereHas('scheduleRun', function (Builder $query): void {
                $query->where('status', ScheduleGenerationRun::StatusPublished);
            });
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
        return TermOffering::modalityOptions();
    }

    public function scheduleRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class, 'schedule_run_id');
    }

    public function schedulingDemand(): BelongsTo
    {
        return $this->belongsTo(SchedulingDemand::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
