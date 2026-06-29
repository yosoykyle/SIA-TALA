<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateScheduleRow extends Model
{
    public const StatusOk = 'ok';

    public const StatusWarning = 'warning';

    public const StatusConflict = 'conflict';

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
        'time_block_key',
        'status',
        'scores',
        'warnings',
        'violations',
        'override_authority',
        'override_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meeting_sequence' => 'integer',
            'day_of_week' => 'integer',
            'scores' => 'array',
            'warnings' => 'array',
            'violations' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public static function committableStatuses(): array
    {
        return [
            self::StatusOk,
            self::StatusWarning,
        ];
    }

    public function scheduleRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class, 'schedule_run_id');
    }

    public function generationRun(): BelongsTo
    {
        return $this->scheduleRun();
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
