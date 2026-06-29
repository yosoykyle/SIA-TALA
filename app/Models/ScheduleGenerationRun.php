<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleGenerationRun extends Model
{
    protected $table = 'schedule_runs';

    public const StatusQueued = 'queued';

    public const StatusDispatching = 'dispatching';

    public const StatusUnderReview = 'under_review';

    public const StatusBlocked = 'blocked';

    public const StatusFailed = 'failed';

    public const StatusPublished = 'published';

    public const StatusSuperseded = 'superseded';

    /**
     * Legacy aliases retained for older references while the scheduling surface is rebaselined.
     */
    public const StatusGenerated = self::StatusQueued;

    public const StatusDraft = self::StatusQueued;

    public const StatusCommitted = self::StatusUnderReview;

    public const StatusAbandoned = self::StatusFailed;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'status',
        'requested_by',
        'input_snapshot',
        'input_hash',
        'solver_version',
        'model_version',
        'runtime_ms',
        'objective_value',
        'diagnostics',
        'candidate_key',
        'published_by',
        'published_at',
        'publication_version',
        'publication_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_snapshot' => 'array',
            'runtime_ms' => 'integer',
            'objective_value' => 'decimal:2',
            'diagnostics' => 'array',
            'published_at' => 'datetime',
            'publication_version' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusQueued => 'Queued',
            self::StatusDispatching => 'Dispatching',
            self::StatusUnderReview => 'Under Review',
            self::StatusBlocked => 'Blocked',
            self::StatusFailed => 'Failed',
            self::StatusPublished => 'Published',
            self::StatusSuperseded => 'Superseded',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            self::StatusQueued => 'warning',
            self::StatusDispatching => 'info',
            self::StatusUnderReview => 'success',
            self::StatusBlocked => 'danger',
            self::StatusFailed => 'danger',
            self::StatusPublished => 'primary',
            self::StatusSuperseded => 'gray',
        ];
    }

    /**
     * Publication remains out of the TAL-62 surface.
     *
     * @return list<string>
     */
    public static function publishableStatuses(): array
    {
        return [];
    }

    public function canBePublished(): bool
    {
        return false;
    }

    public function isPublished(): bool
    {
        return $this->status === self::StatusPublished;
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function candidateRows(): HasMany
    {
        return $this->hasMany(CandidateScheduleRow::class, 'schedule_run_id');
    }

    public function draftRows(): HasMany
    {
        return $this->candidateRows();
    }

    public function sectionMeetings(): HasMany
    {
        return $this->hasMany(SectionMeeting::class, 'schedule_run_id');
    }
}
