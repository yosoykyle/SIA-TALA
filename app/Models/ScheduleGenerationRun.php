<?php

namespace App\Models;

use Database\Factories\ScheduleGenerationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $input_snapshot
 * @property array<string, mixed>|null $diagnostics
 * @property array<string, mixed>|null $quality_measures
 */
class ScheduleGenerationRun extends Model
{
    /** @use HasFactory<ScheduleGenerationRunFactory> */
    use HasFactory;

    public const ContractVersion = 'tala-timetable-v2';

    public const SolverVersion = 'cloud-cp-sat-tala-timetable-v2-lexicographic-v1-deadline-v2';

    public const QualityPolicyLexicographic = 'lexicographic_v1';

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
        'contract_version',
        'solver_version',
        'model_version',
        'quality_policy',
        'runtime_ms',
        'objective_value',
        'quality_measures',
        'diagnostics',
        'candidate_key',
        'candidate_version',
        'candidate_state',
        'candidate_reviewed_by',
        'candidate_reviewed_at',
        'candidate_review_reason',
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
            'quality_measures' => 'array',
            'diagnostics' => 'array',
            'candidate_version' => 'integer',
            'candidate_reviewed_at' => 'datetime',
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
     * @return list<string>
     */
    public static function publishableStatuses(): array
    {
        return [self::StatusUnderReview];
    }

    public function canBePublished(): bool
    {
        if (! in_array($this->status, self::publishableStatuses(), true)) {
            return false;
        }

        if ($this->contract_version === self::ContractVersion && $this->candidate_state !== 'Accepted') {
            return false;
        }

        $summary = $this->publicationSummary();

        return $summary['assignments'] > 0 && $summary['conflicts'] === 0;
    }

    /**
     * @return array{assignments:int,warnings:int,conflicts:int}
     */
    public function publicationSummary(): array
    {
        $candidateRows = $this->relationLoaded('candidateRows')
            ? $this->candidateRows
            : $this->candidateRows()->with('schedulingDemand.termOffering')->get();

        $candidateRows->loadMissing('schedulingDemand.termOffering');

        return [
            'assignments' => $candidateRows->count(),
            'warnings' => $candidateRows->filter(
                fn (CandidateScheduleRow $candidateRow): bool => $candidateRow->hasWarnings(),
            )->count(),
            'conflicts' => $candidateRows->reject(
                fn (CandidateScheduleRow $candidateRow): bool => $candidateRow->isPublishableFor($this),
            )->count(),
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::StatusPublished;
    }

    public function dispatchCycle(): int
    {
        return max(1, (int) ($this->dispatchDiagnostics()['dispatch_cycle'] ?? 1));
    }

    public function solverAttemptCount(): int
    {
        if ($this->relationLoaded('solverAttemptEvents')) {
            return $this->solverAttemptEvents->count();
        }

        return $this->solverAttemptEvents()->count();
    }

    public function latestSolverAttempt(): ?OperationalEvent
    {
        if ($this->relationLoaded('solverAttemptEvents')) {
            return $this->solverAttemptEvents
                ->sortByDesc(fn (OperationalEvent $event): int => (int) $event->id)
                ->first();
        }

        return $this->solverAttemptEvents()
            ->latest('occurred_at')
            ->latest('id')
            ->first();
    }

    public function canRetrySolver(): bool
    {
        $failure = $this->finalSolverFailure();

        return $this->status === self::StatusFailed
            && ($failure['retryable'] ?? false) === true
            && ($failure['final'] ?? false) === true
            && blank($this->candidate_key)
            && $this->published_at === null
            && ! $this->candidateRows()->exists()
            && ! $this->sectionMeetings()->exists();
    }

    /** @return array<string, mixed> */
    public function finalSolverFailure(): array
    {
        $failure = $this->dispatchDiagnostics()['failure'] ?? null;

        return is_array($failure) ? $failure : [];
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

    public function candidateReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_reviewed_by');
    }

    /** @return HasMany<CandidateScheduleRow, $this> */
    public function candidateRows(): HasMany
    {
        return $this->hasMany(CandidateScheduleRow::class, 'schedule_run_id');
    }

    /** @return HasMany<CandidateScheduleRow, $this> */
    public function draftRows(): HasMany
    {
        return $this->candidateRows();
    }

    /** @return HasMany<SectionMeeting, $this> */
    public function sectionMeetings(): HasMany
    {
        return $this->hasMany(SectionMeeting::class, 'schedule_run_id');
    }

    /** @return HasMany<PublishedTimetableVersion, $this> */
    public function timetableVersions(): HasMany
    {
        return $this->hasMany(PublishedTimetableVersion::class, 'schedule_run_id');
    }

    /** @return HasMany<ScheduleRevisionEvent, $this> */
    public function revisionEvents(): HasMany
    {
        return $this->hasMany(ScheduleRevisionEvent::class, 'term_id', 'term_id');
    }

    /** @return HasMany<OperationalEvent, $this> */
    public function operationalEvents(): HasMany
    {
        return $this->hasMany(OperationalEvent::class, 'related_record_id')
            ->where('related_record_type', self::class);
    }

    /** @return HasMany<OperationalEvent, $this> */
    public function solverAttemptEvents(): HasMany
    {
        return $this->operationalEvents()
            ->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationSchedulingSolver)
            ->where('event_type', OperationalEvent::TypeSolverDispatchAttempt);
    }

    /** @return array<string, mixed> */
    private function dispatchDiagnostics(): array
    {
        $diagnostics = $this->getAttribute('diagnostics');
        $dispatch = is_array($diagnostics) ? ($diagnostics['solver_dispatch'] ?? null) : null;

        return is_array($dispatch) ? $dispatch : [];
    }
}
