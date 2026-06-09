<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleGenerationRun extends Model
{
    public const StatusGenerated = 'generated';

    public const StatusDraft = 'draft';

    public const StatusUnderReview = 'under_review';

    public const StatusBlocked = 'blocked';

    public const StatusCommitted = 'committed';

    public const StatusAbandoned = 'abandoned';

    public const StatusSuperseded = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'status',
        'requested_by',
        'generated_at',
        'committed_by',
        'committed_at',
        'constraint_summary',
        'solver_input_snapshot',
        'solver_input_hash',
        'solver_snapshot_captured_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'committed_at' => 'datetime',
            'constraint_summary' => 'array',
            'solver_input_snapshot' => 'array',
            'solver_snapshot_captured_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusGenerated => 'Generated',
            self::StatusDraft => 'Draft',
            self::StatusUnderReview => 'Under Review',
            self::StatusBlocked => 'Blocked',
            self::StatusCommitted => 'Committed',
            self::StatusAbandoned => 'Abandoned',
            self::StatusSuperseded => 'Superseded',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            'info' => self::StatusGenerated,
            'warning' => self::StatusDraft,
            'gray' => self::StatusUnderReview,
            'danger' => self::StatusBlocked,
            'success' => self::StatusCommitted,
        ];
    }

    /**
     * @return list<string>
     */
    public static function committableStatuses(): array
    {
        return [
            self::StatusGenerated,
            self::StatusUnderReview,
        ];
    }

    public function canBeCommitted(): bool
    {
        return in_array($this->status, self::committableStatuses(), true);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function sectionMeetings(): HasMany
    {
        return $this->hasMany(SectionMeeting::class, 'schedule_generation_run_id');
    }

    public function draftRows(): HasMany
    {
        return $this->hasMany(ScheduleDraftRow::class, 'generation_run_id');
    }
}
