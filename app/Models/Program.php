<?php

namespace App\Models;

use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'duration_years',
        'is_active',
    ];

    /**
     * @var array{
     *     available: bool,
     *     status: string,
     *     reason: string,
     *     batch_label: ?string,
     *     curriculum_id: ?int
     * }|null
     */
    protected ?array $cachedAdmissionsAvailability = null;

    private ?string $cachedAdmissionsAvailabilityKey = null;

    public function clearAdmissionsAvailabilityCache(): static
    {
        $this->cachedAdmissionsAvailability = null;
        $this->cachedAdmissionsAvailabilityKey = null;

        return $this;
    }

    public function refresh(): static
    {
        $this->clearAdmissionsAvailabilityCache();

        return parent::refresh();
    }

    /**
     * Applicant-facing program name without the leading "Diploma in"; official records and outputs keep the full name.
     */
    public static function applicantName(string $name): string
    {
        $shortened = preg_replace('/^Diploma in\s+/i', '', $name);

        return filled($shortened) ? $shortened : $name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_years' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<CurriculumVersion, $this> */
    public function curriculumVersions(): HasMany
    {
        return $this->hasMany(CurriculumVersion::class);
    }

    /** @return HasMany<CurriculumVersionBatch, $this> */
    public function curriculumVersionBatches(): HasMany
    {
        return $this->hasMany(CurriculumVersionBatch::class);
    }

    /** @return HasMany<TermCohort, $this> */
    public function termCohorts(): HasMany
    {
        return $this->hasMany(TermCohort::class);
    }

    /** @return HasMany<AdmissionApplication, $this> */
    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class);
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function admissionCycles(): BelongsToMany
    {
        return $this->belongsToMany(AdmissionCycle::class)
            ->withPivot(['accepts_first_year', 'accepts_transferee'])
            ->withTimestamps();
    }

    /**
     * Dynamic historical intake-batch lookback span in years (e.g., 3-yr diploma => 2 yrs back, 4-yr BSIT => 3 yrs back).
     */
    public function intakeBatchLookbackYears(): int
    {
        $duration = (int) ($this->duration_years ?: 3);

        return max(0, min(7, $duration - 1));
    }

    /**
     * Map running year levels (1..duration_years) to expected intake start years for a given operational Academic Year.
     *
     * @return array<int, int>
     */
    public function expectedIntakeYearsForAcademicYear(AcademicYear $operationalYear): array
    {
        $termStartYear = CurriculumVersion::extractAcademicYearStart($operationalYear);
        if ($termStartYear === null) {
            return [];
        }

        $maxYear = max(1, min(8, (int) ($this->duration_years ?: 3)));
        $map = [];

        for ($yearLevel = 1; $yearLevel <= $maxYear; $yearLevel++) {
            $map[$yearLevel] = $termStartYear - ($yearLevel - 1);
        }

        return $map;
    }

    /**
     * Resolve the published (Active or Retired) curriculum assigned to this program for a specific intake start year.
     */
    public function curriculumForIntakeStartYear(int $intakeStartYear): ?CurriculumVersion
    {
        $batches = CurriculumVersionBatch::query()
            ->where('program_id', $this->id)
            ->whereHas('curriculumVersion', fn (Builder $query): Builder => $query
                ->whereIn('state', [
                    CurriculumVersion::StateActive,
                    CurriculumVersion::StateSuperseded,
                ]))
            ->with(['academicYear', 'curriculumVersion.academicYears'])
            ->get();

        $matched = $batches->first(
            fn (CurriculumVersionBatch $batch): bool => $batch->academicYear instanceof AcademicYear
                && CurriculumVersion::extractAcademicYearStart($batch->academicYear) === $intakeStartYear,
        );

        if ($matched?->curriculumVersion instanceof CurriculumVersion) {
            return $matched->curriculumVersion;
        }

        if ($batches->isEmpty() && $this->curriculumVersionBatches()->doesntExist()) {
            return $this->curriculumVersions()
                ->where('state', CurriculumVersion::StateActive)
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * Resolve the published curriculum assigned to this program for a specific Academic Year batch.
     *
     * Falls back to the latest Active curriculum ONLY when the program has no batch assignments yet.
     */
    public function curriculumForAcademicYear(?int $academicYearId): ?CurriculumVersion
    {
        if ($academicYearId !== null) {
            $batch = CurriculumVersionBatch::query()
                ->where('program_id', $this->id)
                ->where('academic_year_id', $academicYearId)
                ->whereHas('curriculumVersion', fn (Builder $query): Builder => $query
                    ->whereIn('state', [
                        CurriculumVersion::StateActive,
                        CurriculumVersion::StateSuperseded,
                    ]))
                ->with('curriculumVersion')
                ->first();

            if ($batch?->curriculumVersion instanceof CurriculumVersion) {
                return $batch->curriculumVersion;
            }
        }

        if ($this->curriculumVersionBatches()->doesntExist()) {
            return $this->curriculumVersions()
                ->where('state', CurriculumVersion::StateActive)
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * Evaluate the 2-condition Admissions availability gate for this program:
     * (1) Program is active, AND
     * (2) Incoming batch Academic Year is assigned to a published (Active or Retired) curriculum.
     *
     * @return array{
     *     available: bool,
     *     status: string,
     *     reason: string,
     *     batch_label: ?string,
     *     curriculum_id: ?int
     * }
     */
    public function admissionsAvailability(?AdmissionCycle $cycle = null): array
    {
        $cacheKey = ($cycle instanceof AdmissionCycle ? 'cycle:'.($cycle->id ?? spl_object_id($cycle)) : 'default')
            .':active:'.((bool) $this->is_active ? '1' : '0');

        if ($this->cachedAdmissionsAvailability !== null && $this->cachedAdmissionsAvailabilityKey === $cacheKey) {
            return $this->cachedAdmissionsAvailability;
        }

        $academicYear = $this->resolveIncomingBatchAcademicYear($cycle);
        $batchLabel = $academicYear instanceof AcademicYear
            ? 'Batch '.CurriculumVersion::formatBatchShort($academicYear)
            : null;

        if (! $this->is_active) {
            $this->cachedAdmissionsAvailabilityKey = $cacheKey;

            return $this->cachedAdmissionsAvailability = [
                'available' => false,
                'status' => 'Inactive',
                'reason' => 'Program is inactive',
                'batch_label' => $batchLabel,
                'curriculum_id' => null,
            ];
        }

        if (! $academicYear instanceof AcademicYear) {
            $this->cachedAdmissionsAvailabilityKey = $cacheKey;

            return $this->cachedAdmissionsAvailability = [
                'available' => false,
                'status' => 'Unavailable',
                'reason' => 'Not available in Admissions: no incoming academic year configured',
                'batch_label' => null,
                'curriculum_id' => null,
            ];
        }

        if ($this->relationLoaded('curriculumVersions')
            && $this->curriculumVersions->every(fn (CurriculumVersion $cv): bool => $cv->relationLoaded('academicYears'))) {
            $matchingCurriculum = $this->curriculumVersions
                ->first(fn (CurriculumVersion $cv): bool => in_array($cv->state, [
                    CurriculumVersion::StateActive,
                    CurriculumVersion::StateSuperseded,
                ], true) && $cv->academicYears->contains('id', $academicYear->id));

            if ($matchingCurriculum instanceof CurriculumVersion) {
                $this->cachedAdmissionsAvailabilityKey = $cacheKey;

                return $this->cachedAdmissionsAvailability = [
                    'available' => true,
                    'status' => 'Available',
                    'reason' => 'Available in Admissions',
                    'batch_label' => $batchLabel,
                    'curriculum_id' => (int) $matchingCurriculum->id,
                ];
            }
        } else {
            $batch = CurriculumVersionBatch::query()
                ->where('program_id', $this->id)
                ->where('academic_year_id', $academicYear->id)
                ->whereHas('curriculumVersion', fn (Builder $query): Builder => $query
                    ->whereIn('state', [
                        CurriculumVersion::StateActive,
                        CurriculumVersion::StateSuperseded,
                    ]))
                ->first();

            if ($batch instanceof CurriculumVersionBatch) {
                $this->cachedAdmissionsAvailabilityKey = $cacheKey;

                return $this->cachedAdmissionsAvailability = [
                    'available' => true,
                    'status' => 'Available',
                    'reason' => 'Available in Admissions',
                    'batch_label' => $batchLabel,
                    'curriculum_id' => (int) $batch->curriculum_version_id,
                ];
            }
        }

        $this->cachedAdmissionsAvailabilityKey = $cacheKey;

        return $this->cachedAdmissionsAvailability = [
            'available' => false,
            'status' => 'Unavailable',
            'reason' => "Not available in Admissions: no curriculum for {$batchLabel}",
            'batch_label' => $batchLabel,
            'curriculum_id' => null,
        ];
    }

    /**
     * Count unresolved AdmissionApplications for this program in open published admission cycles.
     */
    public function openCycleApplicantsCount(): int
    {
        return AdmissionApplication::query()
            ->where('program_id', $this->id)
            ->whereNotIn('application_state', [
                AdmissionApplication::StateWithdrawn,
                AdmissionApplication::StateNotAdmitted,
            ])
            ->whereHas('admissionCycle', fn (Builder $query): Builder => $query
                ->where('state', AdmissionCycle::StatePublished)
                ->where('closes_at', '>', now()))
            ->count();
    }

    /**
     * Determine whether this program is the only active program attached to any open published admission cycle.
     */
    public function isOnlyActiveProgramInOpenCycle(): bool
    {
        $openCycles = $this->admissionCycles()
            ->where('admission_cycles.state', AdmissionCycle::StatePublished)
            ->where('admission_cycles.closes_at', '>', now())
            ->withCount([
                'programs as other_active_programs_count' => fn (Builder $query): Builder => $query
                    ->where('programs.is_active', true)
                    ->where('programs.id', '!=', $this->id),
            ])
            ->get();

        return $openCycles->contains(
            fn (AdmissionCycle $cycle): bool => (int) $cycle->getAttribute('other_active_programs_count') === 0,
        );
    }

    /**
     * Count officially enrolled students in this program for the given term (or the current active term).
     */
    public function officialEnrolledStudentsCount(?int $termId = null): int
    {
        $resolvedTermId = $termId ?? Term::query()
            ->where('state', Term::StateActive)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->value('id');

        return Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->when($resolvedTermId !== null, fn (Builder $query): Builder => $query->where('term_id', $resolvedTermId))
            ->where(function (Builder $query): void {
                $query->whereHas('studentProfile', fn (Builder $profiles): Builder => $profiles->where('program_id', $this->id))
                    ->orWhere(function (Builder $fallback): void {
                        $fallback->whereNull('student_profile_id')
                            ->whereHas('admissionApplication', fn (Builder $apps): Builder => $apps->where('program_id', $this->id));
                    });
            })
            ->count();
    }

    private function resolveIncomingBatchAcademicYear(?AdmissionCycle $cycle = null): ?AcademicYear
    {
        if ($cycle instanceof AdmissionCycle) {
            $cycle->loadMissing('term.academicYear');

            return $cycle->term?->academicYear;
        }

        $openCycle = AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->whereHas('term', fn (Builder $term): Builder => $term->where('state', Term::StateActive))
            ->with('term.academicYear')
            ->orderByDesc('opens_at')
            ->orderByDesc('id')
            ->first();

        if ($openCycle?->term?->academicYear instanceof AcademicYear) {
            return $openCycle->term->academicYear;
        }

        return AcademicYear::resolveCurrentOperational();
    }
}
