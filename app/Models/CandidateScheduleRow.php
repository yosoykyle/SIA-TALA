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
        'generation_run_id',
        'section_id',
        'section_delivery_group_id',
        'subject_id',
        'faculty_id',
        'room',
        'day_of_week',
        'starts_at',
        'ends_at',
        'modality',
        'status',
        'conflict_payload',
        'warning_payload',
        'override_reason',
        'edited_by',
        'edited_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conflict_payload' => 'array',
            'warning_payload' => 'array',
            'edited_at' => 'datetime',
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

    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class, 'generation_run_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function sectionDeliveryGroup(): BelongsTo
    {
        return $this->belongsTo(SectionDeliveryGroup::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
