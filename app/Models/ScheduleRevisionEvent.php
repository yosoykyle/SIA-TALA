<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property CarbonImmutable $effective_date
 * @property array<string, mixed> $old_snapshot_json
 * @property array<string, mixed> $new_snapshot_json
 * @property int $affected_student_count
 * @property int $affected_faculty_count
 * @property CarbonImmutable $created_at
 */
class ScheduleRevisionEvent extends Model
{
    public const ChangeRoom = 'ROOM_CHANGE';

    public const ChangeFacultyReassignment = 'FACULTY_REASSIGNMENT';

    public const ChangeTime = 'TIME_CHANGE';

    public const ChangeDeliveryModality = 'DELIVERY_MODALITY_CHANGE';

    public const ChangeSectionCancellation = 'SECTION_CANCELLATION';

    public $timestamps = false;

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
            'effective_date' => 'immutable_date',
            'old_snapshot_json' => 'array',
            'new_snapshot_json' => 'array',
            'affected_student_count' => 'integer',
            'affected_faculty_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Schedule revision events are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Schedule revision events are immutable.');
        });
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<SectionMeeting, $this> */
    public function sectionMeeting(): BelongsTo
    {
        return $this->belongsTo(SectionMeeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
