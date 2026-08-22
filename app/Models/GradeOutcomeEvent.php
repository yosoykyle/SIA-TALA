<?php

namespace App\Models;

use Database\Factories\GradeOutcomeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $deadline
 * @property Carbon|null $source_term_ends_on
 * @property Carbon|null $released_at
 */
class GradeOutcomeEvent extends Model
{
    /** @use HasFactory<GradeOutcomeEventFactory> */
    use HasFactory;

    public $timestamps = false;

    public const TypeInitialRelease = 'INITIAL_RELEASE';

    public const TypePendingReplacement = 'PENDING_REPLACEMENT';

    public const TypeIncResolution = 'INC_RESOLUTION';

    public const TypePostedCorrection = 'POSTED_CORRECTION';

    public const TypeLifecycleOutcome = 'LIFECYCLE_OUTCOME';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade_roster_row_id',
        'event_type',
        'result_code',
        'source_term_ends_on',
        'inc_completion_note',
        'source_version_id',
        'predecessor_event_id',
        'previous_value',
        'new_value',
        'previous_category',
        'new_category',
        'deadline',
        'authority',
        'reason',
        'evidence_reference',
        'recorded_by',
        'released_at',
        'source_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_value' => 'decimal:4',
            'new_value' => 'decimal:4',
            'deadline' => 'date',
            'source_term_ends_on' => 'date',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GradeRosterRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(GradeRosterRow::class, 'grade_roster_row_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<GradeRosterVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(GradeRosterVersion::class, 'source_version_id');
    }

    /** @return BelongsTo<GradeOutcomeEvent, $this> */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_event_id');
    }

    /** @return HasMany<IncCompletionSubmission, $this> */
    public function completionSubmissions(): HasMany
    {
        return $this->hasMany(IncCompletionSubmission::class);
    }

    /** @return HasMany<IncDeadlineAmendment, $this> */
    public function deadlineAmendments(): HasMany
    {
        return $this->hasMany(IncDeadlineAmendment::class);
    }
}
