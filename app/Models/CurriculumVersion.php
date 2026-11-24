<?php

namespace App\Models;

use Database\Factories\CurriculumVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CurriculumVersion extends Model
{
    /** @use HasFactory<CurriculumVersionFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateSuperseded = 'SUPERSEDED';

    public const StateArchived = 'ARCHIVED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'program_id',
        'copied_from_curriculum_version_id',
        'version_code',
        'name',
        'effective_entry_term_id',
        'state',
        'content_hash',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<self, $this> */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_curriculum_version_id');
    }

    /** @return HasMany<CurriculumVersionBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(CurriculumVersionBatch::class);
    }

    /** @return BelongsToMany<AcademicYear, $this> */
    public function academicYears(): BelongsToMany
    {
        return $this->belongsToMany(AcademicYear::class, 'curriculum_version_batches')
            ->withPivot(['program_id', 'added_by'])
            ->withTimestamps();
    }

    /** @return BelongsTo<Term, $this> */
    public function effectiveEntryTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'effective_entry_term_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<CurriculumEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(CurriculumEntry::class);
    }

    /** @return HasMany<ExternalCompetencyRequirement, $this> */
    public function externalCompetencyRequirements(): HasMany
    {
        return $this->hasMany(ExternalCompetencyRequirement::class);
    }

    /** @return HasMany<StudentProfile, $this> */
    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    /** @return HasMany<TermCohort, $this> */
    public function termCohorts(): HasMany
    {
        return $this->hasMany(TermCohort::class);
    }

    /** @return HasMany<RegistrationProposalVersion, $this> */
    public function registrationProposalVersions(): HasMany
    {
        return $this->hasMany(RegistrationProposalVersion::class);
    }

    /** @return HasMany<GraduationApplication, $this> */
    public function graduationApplications(): HasMany
    {
        return $this->hasMany(GraduationApplication::class);
    }

    /** @return HasMany<DegreeConferral, $this> */
    public function degreeConferrals(): HasMany
    {
        return $this->hasMany(DegreeConferral::class);
    }

    /**
     * Determine whether this version has historical or downstream institutional usage.
     */
    public function isHistoricallyUsed(): bool
    {
        if (in_array($this->state, [self::StateSuperseded, self::StateArchived], true)) {
            return true;
        }

        return $this->studentProfiles()->exists()
            || $this->termCohorts()->exists()
            || $this->registrationProposalVersions()->exists()
            || $this->graduationApplications()->exists()
            || $this->degreeConferrals()->exists()
            || $this->entries()->whereHas('termOfferings')->exists();
    }

    /**
     * Determine whether this curriculum version may still be edited in place.
     */
    public function isEditableDraft(): bool
    {
        return $this->state === self::StateDraft && ! $this->isHistoricallyUsed();
    }

    public function isRetired(): bool
    {
        return $this->state === self::StateSuperseded;
    }

    public function isPublished(): bool
    {
        return in_array($this->state, [self::StateActive, self::StateSuperseded], true);
    }

    /**
     * Auto-generate a batch range label from assigned AcademicYears (e.g. "Batch 2027–28 to 2029–30, 2032–33").
     * Falls back to the curriculum's name or version code when no batches are assigned.
     */
    public function batchLabel(): string
    {
        $years = $this->sortedBatchAcademicYears();

        if ($years->isEmpty()) {
            return filled($this->name) ? (string) $this->name : (string) $this->version_code;
        }

        $runs = [];
        $currentRun = null;

        foreach ($years as $academicYear) {
            $startYear = self::extractAcademicYearStart($academicYear);
            $short = self::formatBatchShort($academicYear);

            if ($currentRun === null) {
                $currentRun = [
                    'start_year' => $startYear,
                    'last_year' => $startYear,
                    'start_short' => $short,
                    'last_short' => $short,
                ];

                continue;
            }

            if ($startYear !== null && $currentRun['last_year'] !== null && $startYear === $currentRun['last_year']) {
                continue;
            }

            if ($startYear !== null && $currentRun['last_year'] !== null && $startYear === $currentRun['last_year'] + 1) {
                $currentRun['last_year'] = $startYear;
                $currentRun['last_short'] = $short;

                continue;
            }

            $runs[] = $currentRun;
            $currentRun = [
                'start_year' => $startYear,
                'last_year' => $startYear,
                'start_short' => $short,
                'last_short' => $short,
            ];
        }

        if ($currentRun !== null) {
            $runs[] = $currentRun;
        }

        $formattedRuns = array_map(
            fn (array $run): string => $run['start_short'] === $run['last_short']
                ? $run['start_short']
                : "{$run['start_short']} to {$run['last_short']}",
            $runs,
        );

        return 'Batch '.implode(', ', $formattedRuns);
    }

    public function startBatchLabel(): ?string
    {
        $first = $this->sortedBatchAcademicYears()->first();

        return $first instanceof AcademicYear ? 'Batch '.self::formatBatchShort($first) : null;
    }

    public function lastBatchLabel(): ?string
    {
        $last = $this->sortedBatchAcademicYears()->last();

        return $last instanceof AcademicYear ? 'Batch '.self::formatBatchShort($last) : null;
    }

    public function studentCountForBatches(): int
    {
        return $this->studentProfiles()
            ->whereNull('archived_at')
            ->whereNull('merged_into_id')
            ->count();
    }

    public static function formatBatchShort(AcademicYear $academicYear): string
    {
        $label = trim((string) $academicYear->label);

        if (preg_match('/((?:19|20)\d{2})\s*[–-]\s*(\d{2,4})/', $label, $matches) === 1) {
            $start = $matches[1];
            $end = strlen($matches[2]) === 4 ? substr($matches[2], -2) : $matches[2];

            return "{$start}–{$end}";
        }

        $startYear = self::extractAcademicYearStart($academicYear);

        if ($startYear !== null) {
            $end = substr((string) ($startYear + 1), -2);

            return "{$startYear}–{$end}";
        }

        return $label !== '' ? $label : "AY #{$academicYear->id}";
    }

    public static function extractAcademicYearStart(AcademicYear $academicYear): ?int
    {
        $label = trim((string) $academicYear->label);

        if (preg_match('/((?:19|20)\d{2})/', $label, $matches) === 1) {
            return (int) $matches[1];
        }

        return $academicYear->starts_on?->year;
    }

    /**
     * @return Collection<int, AcademicYear>
     */
    private function sortedBatchAcademicYears(): Collection
    {
        if ($this->relationLoaded('academicYears')) {
            $years = $this->academicYears;
        } elseif ($this->relationLoaded('batches')) {
            $this->batches->loadMissing('academicYear');
            $years = $this->batches
                ->map(fn (CurriculumVersionBatch $batch): ?AcademicYear => $batch->academicYear)
                ->filter(fn (?AcademicYear $year): bool => $year instanceof AcademicYear)
                ->values();
        } else {
            $years = $this->academicYears()->get();
        }

        return $years
            ->unique('id')
            ->sortBy(fn (AcademicYear $year): array => [
                self::extractAcademicYearStart($year) ?? PHP_INT_MAX,
                $year->starts_on?->timestamp ?? PHP_INT_MAX,
                (int) $year->id,
            ])
            ->values();
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateSuperseded => 'Retired',
            self::StateArchived => 'Archived',
        ];
    }
}
