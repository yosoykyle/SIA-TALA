<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\RegistrationProposalItem;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EnrollmentContinuationSummary
{
    public const StatusEnrolled = 'enrolled';

    public const StatusDidNotProceed = 'did_not_proceed';

    public const StatusInProgress = 'in_progress';

    /**
     * Summarize continuing student enrollment from the prior term into the active term.
     *
     * @return array{
     *     active_term: ?array{id: int, label: string},
     *     prior_term: ?array{id: int, label: string},
     *     enrolled_count: int,
     *     did_not_proceed_count: int,
     *     in_progress_count: int,
     *     prior_cohort_count: int,
     *     empty_note: ?string,
     *     progressions: list<array{
     *         key: string,
     *         from_year: ?int,
     *         to_year: ?int,
     *         from_label: string,
     *         to_label: string,
     *         label: string,
     *         enrolled: int,
     *         did_not_proceed: int,
     *         in_progress: int,
     *     }>,
     *     students: list<array{
     *         student_profile_id: int,
     *         student_number: string,
     *         student_name: string,
     *         program_id: ?int,
     *         program_code: string,
     *         program_name: string,
     *         prior_year_level: ?int,
     *         prior_year_label: string,
     *         current_year_level: ?int,
     *         current_year_label: ?string,
     *         progression_label: string,
     *         status: string,
     *         status_label: string,
     *         active_enrollment_id: ?int,
     *     }>,
     * }
     */
    public function forActiveTerm(?Term $activeTerm = null, ?Term $priorTerm = null): array
    {
        $activeTerm ??= Term::query()
            ->with('academicYear')
            ->where('state', Term::StateActive)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        if (! $activeTerm instanceof Term) {
            return $this->emptySummary(null, null, 'No active term is currently open.');
        }

        $activeTerm->loadMissing('academicYear');
        $priorTerm ??= $this->resolvePriorTerm($activeTerm);

        if (! $priorTerm instanceof Term) {
            return $this->emptySummary($activeTerm, null, 'No prior term is available to compare yet.');
        }

        $priorTerm->loadMissing('academicYear');

        $priorEnrollments = Enrollment::query()
            ->where('term_id', $priorTerm->id)
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->whereNotNull('student_profile_id')
            ->with([
                'studentProfile' => fn ($query) => $query
                    ->with(['program', 'curriculumVersion'])
                    ->withExists('degreeConferrals'),
                'studentSection.classOfferings.termOffering.curriculumEntry',
                'studentSection.program',
                'courseEnrollments.termOffering.curriculumEntry',
                'currentProposalVersion.curriculumVersion.program',
                'currentProposalVersion.items.termOffering.curriculumEntry',
                'currentCorVersion',
            ])
            ->orderByDesc('id')
            ->get()
            ->unique('student_profile_id')
            ->values();

        if ($priorEnrollments->isEmpty()) {
            return $this->emptySummary($activeTerm, $priorTerm, 'No official enrollments in the prior term.');
        }

        $studentProfileIds = $priorEnrollments
            ->pluck('student_profile_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $activeByStudent = Enrollment::query()
            ->where('term_id', $activeTerm->id)
            ->whereIn('student_profile_id', $studentProfileIds)
            ->with([
                'studentSection.classOfferings.termOffering.curriculumEntry',
                'studentSection.program',
                'courseEnrollments.termOffering.curriculumEntry',
                'currentProposalVersion.curriculumVersion.program',
                'currentProposalVersion.items.termOffering.curriculumEntry',
                'currentCorVersion',
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_profile_id')
            ->map(fn (Collection $group): ?Enrollment => $this->pickBestActiveEnrollment($group));

        $sameAcademicYear = $priorTerm->academic_year_id !== null
            && $activeTerm->academic_year_id !== null
            && (int) $priorTerm->academic_year_id === (int) $activeTerm->academic_year_id;

        $enrolledCount = 0;
        $didNotProceedCount = 0;
        $inProgressCount = 0;
        $progressions = [];
        $students = [];

        foreach ($priorEnrollments as $priorEnrollment) {
            $profile = $priorEnrollment->studentProfile;

            if (! $profile instanceof StudentProfile) {
                continue;
            }

            /** @var Enrollment|null $activeEnrollment */
            $activeEnrollment = $activeByStudent->get((int) $profile->id);

            if ($activeEnrollment instanceof Enrollment
                && $activeEnrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
                $status = self::StatusEnrolled;
            } elseif ($activeEnrollment instanceof Enrollment
                && $activeEnrollment->canonical_outcome === Enrollment::OutcomeInProgress) {
                $status = self::StatusInProgress;
            } else {
                if ($this->isExcludedFromContinuation($profile)) {
                    continue;
                }

                $status = self::StatusDidNotProceed;
            }

            $priorProgram = $this->resolveProgram($priorEnrollment, $profile);
            $displayProgram = $activeEnrollment instanceof Enrollment
                ? ($this->resolveProgram($activeEnrollment, $profile) ?? $priorProgram)
                : $priorProgram;

            $priorYear = $this->resolveYearLevel($priorEnrollment);
            $durationYears = max(1, (int) ($priorProgram?->duration_years ?? $profile->program?->duration_years ?? 3));
            $expectedToYear = $this->expectedTargetYear($priorYear, $sameAcademicYear, $durationYears);

            if ($status === self::StatusDidNotProceed) {
                $currentYear = null;
                $toYear = $expectedToYear;
                $didNotProceedCount++;
            } else {
                $currentYear = $activeEnrollment instanceof Enrollment ? $this->resolveYearLevel($activeEnrollment) : null;
                $toYear = $currentYear ?? $expectedToYear;

                if ($status === self::StatusEnrolled) {
                    $enrolledCount++;
                } else {
                    $inProgressCount++;
                }
            }

            $fromLabel = $priorYear !== null ? self::ordinalYear($priorYear) : 'Unassigned';
            $toLabel = $toYear !== null ? self::ordinalYear($toYear) : 'Unassigned';
            $progressionLabel = $this->progressionLabel($priorYear, $toYear, $sameAcademicYear);
            $rowKey = ($priorYear ?? 0).':'.($toYear ?? 0);

            if (! isset($progressions[$rowKey])) {
                $progressions[$rowKey] = [
                    'key' => $rowKey,
                    'from_year' => $priorYear,
                    'to_year' => $toYear,
                    'from_label' => $fromLabel,
                    'to_label' => $toLabel,
                    'label' => $progressionLabel,
                    'enrolled' => 0,
                    'did_not_proceed' => 0,
                    'in_progress' => 0,
                ];
            }

            if ($status === self::StatusEnrolled) {
                $progressions[$rowKey]['enrolled']++;
            } elseif ($status === self::StatusInProgress) {
                $progressions[$rowKey]['in_progress']++;
            } else {
                $progressions[$rowKey]['did_not_proceed']++;
            }

            $students[] = [
                'student_profile_id' => (int) $profile->id,
                'student_number' => (string) ($profile->student_number ?? ''),
                'student_name' => $this->studentName($profile),
                'program_id' => $displayProgram?->id !== null ? (int) $displayProgram->id : null,
                'program_code' => (string) ($displayProgram?->code ?? '—'),
                'program_name' => (string) ($displayProgram?->name ?? 'Unassigned'),
                'prior_year_level' => $priorYear,
                'prior_year_label' => $priorYear !== null ? self::ordinalYear($priorYear).' year' : 'Unassigned',
                'current_year_level' => $currentYear,
                'current_year_label' => $currentYear !== null ? self::ordinalYear($currentYear).' year' : null,
                'progression_label' => $progressionLabel,
                'status' => $status,
                'status_label' => match ($status) {
                    self::StatusEnrolled => 'Enrolled',
                    self::StatusInProgress => 'Still in progress',
                    default => 'Did not proceed',
                },
                'active_enrollment_id' => $activeEnrollment?->id !== null ? (int) $activeEnrollment->id : null,
            ];
        }

        usort($progressions, function (array $left, array $right): int {
            $fromCompare = ($left['from_year'] ?? 999) <=> ($right['from_year'] ?? 999);
            if ($fromCompare !== 0) {
                return $fromCompare;
            }

            return ($right['to_year'] ?? -1) <=> ($left['to_year'] ?? -1);
        });

        $statusRank = [
            self::StatusDidNotProceed => 0,
            self::StatusInProgress => 1,
            self::StatusEnrolled => 2,
        ];

        usort($students, function (array $left, array $right) use ($statusRank): int {
            $rankCompare = ($statusRank[$left['status']] ?? 9) <=> ($statusRank[$right['status']] ?? 9);
            if ($rankCompare !== 0) {
                return $rankCompare;
            }

            $yearCompare = ($left['prior_year_level'] ?? 999) <=> ($right['prior_year_level'] ?? 999);
            if ($yearCompare !== 0) {
                return $yearCompare;
            }

            return strcmp($left['student_name'], $right['student_name']);
        });

        $priorCohortCount = count($students);

        return [
            'active_term' => [
                'id' => (int) $activeTerm->id,
                'label' => $this->termLabel($activeTerm),
            ],
            'prior_term' => [
                'id' => (int) $priorTerm->id,
                'label' => $this->termLabel($priorTerm),
            ],
            'enrolled_count' => $enrolledCount,
            'did_not_proceed_count' => $didNotProceedCount,
            'in_progress_count' => $inProgressCount,
            'prior_cohort_count' => $priorCohortCount,
            'empty_note' => $priorCohortCount === 0 ? 'All prior-term students graduated or exited.' : null,
            'progressions' => array_values($progressions),
            'students' => $students,
        ];
    }

    public static function ordinalYear(int $year): string
    {
        return match ($year) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => $year.'th',
        };
    }

    private function resolvePriorTerm(Term $activeTerm): ?Term
    {
        $base = Term::query()
            ->with('academicYear')
            ->where('id', '!=', $activeTerm->id)
            ->where('state', '!=', Term::StateDraft)
            ->where(function (Builder $query) use ($activeTerm): void {
                if ($activeTerm->starts_on !== null) {
                    $query->where('starts_on', '<', $activeTerm->starts_on)
                        ->orWhere(function (Builder $sameStart) use ($activeTerm): void {
                            $sameStart->where('starts_on', $activeTerm->starts_on)
                                ->where('id', '<', $activeTerm->id);
                        });
                } else {
                    $query->where('id', '<', $activeTerm->id);
                }
            })
            ->orderByDesc('starts_on')
            ->orderByDesc('id');

        return (clone $base)
            ->whereHas('enrollments', fn (Builder $query): Builder => $query
                ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled))
            ->first()
            ?? $base->first();
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function pickBestActiveEnrollment(Collection $enrollments): ?Enrollment
    {
        $official = $enrollments->firstWhere('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled);
        if ($official instanceof Enrollment) {
            return $official;
        }

        $inProgress = $enrollments->firstWhere('canonical_outcome', Enrollment::OutcomeInProgress);
        if ($inProgress instanceof Enrollment) {
            return $inProgress;
        }

        return null;
    }

    private function isExcludedFromContinuation(StudentProfile $profile): bool
    {
        if ($profile->blocksEnrollmentByLifecycle()) {
            return true;
        }

        if ((bool) $profile->getAttribute('degree_conferrals_exists')) {
            return true;
        }

        return false;
    }

    private function resolveProgram(Enrollment $enrollment, StudentProfile $profile): ?Program
    {
        return $enrollment->currentProposalVersion?->curriculumVersion?->program
            ?? $enrollment->studentSection?->program
            ?? $profile->program;
    }

    private function resolveYearLevel(Enrollment $enrollment): ?int
    {
        $sectionLevel = $enrollment->studentSection?->resolvedYearLevel();
        if ($sectionLevel !== null) {
            return $sectionLevel;
        }

        $activeCourses = $enrollment->courseEnrollments
            ->filter(fn (CourseEnrollment $courseEnrollment): bool => $courseEnrollment->status === CourseEnrollment::StatusActive
                && $courseEnrollment->is_current);

        $courseSource = $activeCourses->isNotEmpty() ? $activeCourses : $enrollment->courseEnrollments;
        $courseYearLevel = $this->dominantYearLevel(
            $courseSource->map(
                fn (CourseEnrollment $courseEnrollment): ?int => TermCohort::parseYearLevelValue(
                    $courseEnrollment->termOffering?->curriculumEntry?->year_level,
                ),
            ),
        );

        if ($courseYearLevel !== null) {
            return $courseYearLevel;
        }

        $proposal = $enrollment->currentProposalVersion;
        if ($proposal !== null) {
            $snapshotLevel = TermCohort::parseYearLevelValue(data_get($proposal->source_snapshot, 'unit_load.year_level'));
            if ($snapshotLevel !== null) {
                return $snapshotLevel;
            }

            $proposalYearLevel = $this->dominantYearLevel(
                $proposal->items->map(
                    fn (RegistrationProposalItem $item): ?int => TermCohort::parseYearLevelValue(
                        $item->termOffering?->curriculumEntry?->year_level,
                    ),
                ),
            );

            if ($proposalYearLevel !== null) {
                return $proposalYearLevel;
            }
        }

        $corLevel = data_get($enrollment->currentCorVersion?->snapshot, 'state.curriculum_level')
            ?? data_get($enrollment->currentCorVersion?->snapshot, 'curriculum_level');

        if (is_string($corLevel) && trim($corLevel) !== '') {
            $parsed = collect(explode(',', $corLevel))
                ->map(fn (string $part): ?int => TermCohort::parseYearLevelValue($part))
                ->filter()
                ->values();

            if ($parsed->isNotEmpty()) {
                return (int) $parsed->max();
            }
        }

        return TermCohort::parseYearLevelValue($corLevel);
    }

    /**
     * @param  Collection<int, ?int>  $levels
     */
    private function dominantYearLevel(Collection $levels): ?int
    {
        $counts = [];

        foreach ($levels as $level) {
            if ($level !== null) {
                $counts[$level] = ($counts[$level] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        uksort($counts, function (int $left, int $right) use ($counts): int {
            $countCompare = $counts[$right] <=> $counts[$left];
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return $right <=> $left;
        });

        return (int) array_key_first($counts);
    }

    private function expectedTargetYear(?int $priorYear, bool $sameAcademicYear, int $durationYears): ?int
    {
        if ($priorYear === null) {
            return null;
        }

        if ($sameAcademicYear) {
            return $priorYear;
        }

        if ($priorYear >= $durationYears) {
            return $priorYear;
        }

        return $priorYear + 1;
    }

    private function progressionLabel(?int $fromYear, ?int $toYear, bool $sameAcademicYear): string
    {
        if ($fromYear === null && $toYear === null) {
            return 'Unspecified';
        }

        if ($fromYear !== null && $toYear !== null) {
            $base = self::ordinalYear($fromYear).' → '.self::ordinalYear($toYear);

            if (! $sameAcademicYear && $toYear <= $fromYear) {
                return $base.' (repeat)';
            }

            return $base;
        }

        if ($fromYear !== null) {
            return self::ordinalYear($fromYear).' → Unassigned';
        }

        return 'Unassigned → '.self::ordinalYear((int) $toYear);
    }

    private function studentName(StudentProfile $profile): string
    {
        $name = trim(implode(' ', array_filter([
            $profile->first_name,
            $profile->middle_name,
            $profile->last_name,
        ])));

        return $name !== '' ? $name : ($profile->student_number ?: 'Student #'.$profile->id);
    }

    private function termLabel(Term $term): string
    {
        $academicYear = $term->academicYear?->label;
        if (filled($academicYear) && ! str_contains((string) $term->label, (string) $academicYear)) {
            return trim($term->label.' ('.$academicYear.')');
        }

        return (string) $term->label;
    }

    /**
     * @return array{
     *     active_term: ?array{id: int, label: string},
     *     prior_term: ?array{id: int, label: string},
     *     enrolled_count: int,
     *     did_not_proceed_count: int,
     *     in_progress_count: int,
     *     prior_cohort_count: int,
     *     empty_note: ?string,
     *     progressions: list<array{
     *         key: string,
     *         from_year: ?int,
     *         to_year: ?int,
     *         from_label: string,
     *         to_label: string,
     *         label: string,
     *         enrolled: int,
     *         did_not_proceed: int,
     *         in_progress: int,
     *     }>,
     *     students: list<array{
     *         student_profile_id: int,
     *         student_number: string,
     *         student_name: string,
     *         program_id: ?int,
     *         program_code: string,
     *         program_name: string,
     *         prior_year_level: ?int,
     *         prior_year_label: string,
     *         current_year_level: ?int,
     *         current_year_label: ?string,
     *         progression_label: string,
     *         status: string,
     *         status_label: string,
     *         active_enrollment_id: ?int,
     *     }>,
     * }
     */
    private function emptySummary(?Term $activeTerm, ?Term $priorTerm, string $note): array
    {
        return [
            'active_term' => $activeTerm instanceof Term
                ? ['id' => (int) $activeTerm->id, 'label' => $this->termLabel($activeTerm)]
                : null,
            'prior_term' => $priorTerm instanceof Term
                ? ['id' => (int) $priorTerm->id, 'label' => $this->termLabel($priorTerm)]
                : null,
            'enrolled_count' => 0,
            'did_not_proceed_count' => 0,
            'in_progress_count' => 0,
            'prior_cohort_count' => 0,
            'empty_note' => $note,
            'progressions' => [],
            'students' => [],
        ];
    }
}
