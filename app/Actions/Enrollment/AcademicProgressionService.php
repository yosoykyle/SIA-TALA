<?php

namespace App\Actions\Enrollment;

use App\Models\AcademicDecision;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\EnrollmentException;
use App\Models\GradeRosterRow;
use App\Models\PriorAcademicCredit;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AcademicProgressionService
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(StudentProfile $studentProfile, ?Term $targetTerm = null): array
    {
        $entries = CurriculumEntry::query()
            ->with(['courseSpecification.course', 'courseSpecification.requirements.relatedCourse'])
            ->where('curriculum_version_id', $studentProfile->curriculum_version_id)
            ->orderBy('year_level')
            ->orderBy('term_label')
            ->orderBy('sequence')
            ->get();

        if ($entries->isEmpty()) {
            return $this->missingBaselineResult($studentProfile);
        }

        $allAcademicRows = $this->allAcademicRows($studentProfile);
        $academicRows = $this->bestAcademicRowByCourse($allAcademicRows);
        $allPriorCredits = $this->allPriorAcademicCredits($studentProfile);
        $priorCredits = $this->bestPriorCreditByCourse($allPriorCredits);
        $currentCourseIds = $this->currentCourseIds($studentProfile, $targetTerm);
        $shiftCredits = $this->acceptedShiftCredits($studentProfile);
        $exceptions = $this->activeExceptions($studentProfile, $targetTerm);
        $offerings = $this->availableOfferings($entries, $targetTerm);
        $equivalentCourseIdsByCourseId = $this->equivalentCourseIdsByCourseId($entries);
        $completed = [];
        $backSubjects = [];
        $blockers = [];
        $suggestions = [];

        foreach ($entries as $entry) {
            $course = $entry->courseSpecification?->course;

            if ($course === null) {
                continue;
            }

            $courseId = (int) $course->id;
            $matchingCourseIds = $equivalentCourseIdsByCourseId[$courseId] ?? [$courseId];
            $row = $this->resolveMatchingAcademicRow($matchingCourseIds, $academicRows);
            $credit = $shiftCredits->get((int) $entry->id);
            $priorCredit = $this->resolveMatchingPriorCredit($matchingCourseIds, $priorCredits);
            $completion = $this->completionSource($row, $credit, $priorCredit);

            if ($completion !== null) {
                $completed[] = $this->fact($entry, 'completed', $completion);

                continue;
            }

            $outcomeBlocker = $this->outcomeBlocker($entry, $row, $priorCredit);

            if ($outcomeBlocker !== null) {
                $blockers[] = $outcomeBlocker;

                continue;
            }

            if ($this->isBackSubject($row, $studentProfile, $courseId, $priorCredit)) {
                $backSubjects[] = $this->fact($entry, 'back_subject', $this->rowSource($row) ?? $this->priorCreditSource($priorCredit));
            }

            $offering = $offerings->get((int) $entry->id);

            if (! $offering instanceof TermOffering) {
                continue;
            }

            $requirementResult = $this->requirementsPass(
                $entry,
                $offering,
                $academicRows,
                $shiftCredits,
                $priorCredits,
                $currentCourseIds,
                $exceptions,
            );

            if ($requirementResult['passed']) {
                $suggestions[] = [
                    ...$this->fact($entry, 'suggested', null),
                    'term_offering_id' => (int) $offering->id,
                    'offering_category' => $offering->category,
                ];
            } else {
                $blockers = [...$blockers, ...$requirementResult['blockers']];
            }
        }

        $gwa = $this->computePrd05Gwa($allAcademicRows, $allPriorCredits, $shiftCredits, $entries);
        $standing = $this->recommendedStanding($studentProfile, $entries, $completed, $backSubjects, $blockers, $currentCourseIds);
        $recommendation = $this->recommendationResult($studentProfile, $standing);

        return [
            'student_profile_id' => (int) $studentProfile->id,
            'curriculum_version_id' => (int) $studentProfile->curriculum_version_id,
            'curriculum_length' => $entries->pluck('year_level')->unique()->count(),
            'standing' => $standing,
            'authorized_standing' => $studentProfile->academic_standing,
            'recommendation' => $recommendation,
            'completed' => $completed,
            'back_subjects' => $backSubjects,
            'blockers' => collect($blockers)->unique(fn (array $item): string => $item['key'])->values()->all(),
            'suggestions' => $suggestions,
            'current_course_ids' => $currentCourseIds->values()->all(),
            'gwa' => $gwa,
            'facts' => [
                'required_count' => $entries->where('requirement_group', CurriculumEntry::RequirementGroupRequired)->count(),
                'completed_count' => count($completed),
                'back_subject_count' => count($backSubjects),
                'blocker_count' => count($blockers),
            ],
        ];
    }

    public function confirmStanding(StudentProfile $studentProfile, string $standing, User $actor, string $reason): StudentProfile
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may confirm academic standing.');
        }

        if (! in_array($standing, self::standingValues(), true) || trim($reason) === '') {
            throw new RuntimeException('A valid standing and reason are required.');
        }

        return DB::transaction(function () use ($studentProfile, $standing, $actor, $reason): StudentProfile {
            $locked = StudentProfile::query()->lockForUpdate()->findOrFail($studentProfile->id);
            $previous = $locked->academic_standing;
            $locked->update(['academic_standing' => $standing]);
            $this->syncAcademicDecisionForStanding($locked, $standing, $actor, $reason);

            DB::table('activity_log')->insert([
                'log_name' => 'academic_progression',
                'description' => 'Academic standing confirmed.',
                'subject_type' => StudentProfile::class,
                'subject_id' => $locked->id,
                'event' => 'academic_standing_confirmed',
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'properties' => json_encode(['previous' => $previous, 'current' => $standing, 'reason' => $reason]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function syncAcademicDecisionForStanding(StudentProfile $student, string $standing, User $actor, string $reason): void
    {
        $desiredEffect = match ($standing) {
            StudentProfile::StandingMustRepeatYear => AcademicDecision::EffectBlocked,
            StudentProfile::StandingProbationary,
            StudentProfile::StandingDeficient,
            StudentProfile::StandingBlockedByPrerequisite => AcademicDecision::EffectAdvisingRequired,
            StudentProfile::StandingRegular,
            StudentProfile::StandingCompletionCandidate,
            StudentProfile::StandingGraduationCandidate => AcademicDecision::EffectAllowed,
            default => null,
        };

        if ($desiredEffect === null) {
            return;
        }

        $activeStudentWide = AcademicDecision::query()
            ->where('student_profile_id', $student->id)
            ->whereNull('term_id')
            ->where('state', AcademicDecision::StateActive);

        if ($desiredEffect === AcademicDecision::EffectAllowed
            && ! (clone $activeStudentWide)->where('effect', '!=', AcademicDecision::EffectAllowed)->exists()) {
            return;
        }

        $activeStudentWide->update(['state' => AcademicDecision::StateSuperseded]);

        $schoolDate = today('Asia/Manila');
        AcademicDecision::query()->create([
            'student_profile_id' => $student->id,
            'term_id' => null,
            'effect' => $desiredEffect,
            'authority_reference' => 'ACADEMIC-STANDING-CONFIRMED',
            'authority_date' => $schoolDate,
            'reason' => trim($reason),
            'effective_from' => $schoolDate,
            'effective_until' => null,
            'state' => AcademicDecision::StateActive,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
        ]);
    }

    /** @return list<string> */
    public static function standingValues(): array
    {
        return [
            StudentProfile::StandingRegular, StudentProfile::StandingIrregular,
            StudentProfile::StandingProbationary, StudentProfile::StandingDeficient,
            StudentProfile::StandingBlockedByPrerequisite, StudentProfile::StandingMustRepeatYear,
            StudentProfile::StandingCompletionCandidate, StudentProfile::StandingGraduationCandidate,
            StudentProfile::StandingNotYetEvaluated,
        ];
    }

    /** @return array<string, string> */
    public static function standingOptions(): array
    {
        return collect(self::standingValues())
            ->mapWithKeys(fn (string $standing): array => [$standing => self::standingLabel($standing)])
            ->all();
    }

    public static function standingLabel(?string $standing): string
    {
        return filled($standing)
            ? str((string) $standing)->headline()->toString()
            : StudentProfile::StandingNotYetEvaluated;
    }

    /** @param array<string, mixed> $blocker */
    public static function blockerMessage(array $blocker): string
    {
        if (($blocker['key'] ?? null) === 'missing_curriculum_baseline') {
            return 'The curriculum baseline is unavailable, so academic progression cannot be evaluated yet.';
        }

        $course = filled($blocker['course_code'] ?? null)
            ? (string) $blocker['course_code']
            : 'A curriculum requirement';

        if (($blocker['kind'] ?? null) === 'prerequisite') {
            $alternatives = collect((array) ($blocker['alternatives'] ?? []))
                ->filter()
                ->implode(' or ');

            return $alternatives !== ''
                ? "{$course} requires {$alternatives} before enrollment."
                : "{$course} has an unresolved prerequisite.";
        }

        return match ($blocker['reason'] ?? null) {
            'active_inc' => "{$course} has an unresolved incomplete grade.",
            'pending_grade' => "{$course} is waiting for an official released grade.",
            'failed' => "{$course} has a failed prerequisite that requires academic review.",
            'missing_history' => "{$course} is missing the required released academic history.",
            default => "{$course} requires Registrar academic review.",
        };
    }

    /** @return Collection<int, GradeRosterRow> */
    private function allAcademicRows(StudentProfile $studentProfile): Collection
    {
        return GradeRosterRow::query()
            ->with('courseEnrollment.termOffering.curriculumEntry.courseSpecification.course')
            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('student_profile_id', $studentProfile->id))
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * @param  Collection<int, GradeRosterRow>  $rows
     * @return Collection<int, GradeRosterRow>
     */
    private function bestAcademicRowByCourse(Collection $rows): Collection
    {
        return $rows->reduce(function (Collection $carry, GradeRosterRow $row): Collection {
            $courseId = $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course_id;
            if ($courseId === null) {
                return $carry;
            }

            $courseId = (int) $courseId;
            $existing = $carry->get($courseId);
            $rowPasses = $this->numericOutcomeMeets($row) || ($row->released_at !== null && $row->current_outcome_code === 'TC');
            $existingPasses = $existing instanceof GradeRosterRow
                && ($this->numericOutcomeMeets($existing) || ($existing->released_at !== null && $existing->current_outcome_code === 'TC'));

            if (! $existing instanceof GradeRosterRow
                || (! $existingPasses && $rowPasses)
                || ($existingPasses === $rowPasses && $row->id > $existing->id)) {
                $carry->put($courseId, $row);
            }

            return $carry;
        }, collect());
    }

    /** @return Collection<int, PriorAcademicCredit> */
    private function allPriorAcademicCredits(StudentProfile $studentProfile): Collection
    {
        return PriorAcademicCredit::query()
            ->active()
            ->where('student_profile_id', $studentProfile->id)
            ->with('courseSpecification')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * @param  Collection<int, PriorAcademicCredit>  $credits
     * @return Collection<int, PriorAcademicCredit>
     */
    private function bestPriorCreditByCourse(Collection $credits): Collection
    {
        return $credits->reduce(function (Collection $carry, PriorAcademicCredit $credit): Collection {
            $courseId = $credit->courseSpecification?->course_id;

            if ($courseId === null) {
                return $carry;
            }

            $courseId = (int) $courseId;
            $existing = $carry->get($courseId);

            if (! $existing instanceof PriorAcademicCredit
                || (! $existing->isPassing() && $credit->isPassing())
                || ($existing->isPassing() === $credit->isPassing() && $credit->id > $existing->id)) {
                $carry->put($courseId, $credit);
            }

            return $carry;
        }, collect());
    }

    /**
     * @param  Collection<int, GradeRosterRow>  $allRows
     * @param  Collection<int, PriorAcademicCredit>  $allPriorCredits
     * @param  Collection<int, ProgramShiftCreditEntry>  $shiftCredits
     * @param  EloquentCollection<int, CurriculumEntry>  $entries
     */
    private function computePrd05Gwa(
        Collection $allRows,
        Collection $allPriorCredits,
        Collection $shiftCredits,
        EloquentCollection $entries,
    ): ?string {
        $scaledUnits = 0;
        $scaledWeighted = 0;

        foreach ($allRows as $row) {
            if ($row->released_at === null || ! is_numeric($row->current_outcome_code)) {
                continue;
            }

            $spec = $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification;
            if (! $spec instanceof CourseSpecification || CourseSpecification::isExcludedFromGwa($spec->academic_classification)) {
                continue;
            }

            $units = (float) ($row->courseEnrollment?->units_snapshot ?: $spec->credit_units);
            if ($units <= 0.0) {
                continue;
            }

            $attemptUnits = $this->scaledDecimal(number_format($units, 2, '.', ''), 2);
            $scaledUnits += $attemptUnits;
            $scaledWeighted += $this->scaledDecimal((string) $row->current_outcome_code, 4) * $attemptUnits;
        }

        foreach ($allPriorCredits as $priorCredit) {
            if ($priorCredit->isExternalTransfer()) {
                continue;
            }

            if (in_array($priorCredit->result_status, [PriorAcademicCredit::ResultInc, PriorAcademicCredit::ResultDropped], true)) {
                continue;
            }

            $spec = $priorCredit->courseSpecification;
            if (! $spec instanceof CourseSpecification || CourseSpecification::isExcludedFromGwa($spec->academic_classification)) {
                continue;
            }

            $code = $priorCredit->effectiveResultCode();
            if (! is_numeric($code)) {
                continue;
            }

            $units = (float) ($priorCredit->units > 0 ? $priorCredit->units : $spec->credit_units);
            if ($units <= 0.0) {
                continue;
            }

            $attemptUnits = $this->scaledDecimal(number_format($units, 2, '.', ''), 2);
            $scaledUnits += $attemptUnits;
            $scaledWeighted += $this->scaledDecimal($code, 4) * $attemptUnits;
        }

        $entriesById = $entries->keyBy('id');
        foreach ($shiftCredits as $shiftCredit) {
            if ($shiftCredit->source_grade_outcome_event_id !== null
                || $shiftCredit->source_prior_academic_credit_id !== null
                || ! is_numeric($shiftCredit->numeric_grade)) {
                continue;
            }

            $entry = $entriesById->get((int) $shiftCredit->curriculum_entry_id);
            $spec = $entry?->courseSpecification;
            if (! $spec instanceof CourseSpecification || CourseSpecification::isExcludedFromGwa($spec->academic_classification)) {
                continue;
            }

            $units = (float) $spec->credit_units;
            if ($units <= 0.0) {
                continue;
            }

            $attemptUnits = $this->scaledDecimal(number_format($units, 2, '.', ''), 2);
            $scaledUnits += $attemptUnits;
            $scaledWeighted += $this->scaledDecimal((string) $shiftCredit->numeric_grade, 4) * $attemptUnits;
        }

        if ($scaledUnits === 0) {
            return null;
        }

        $denominator = $scaledUnits * 10000;
        $numerator = $scaledWeighted * 100;
        $hundredths = intdiv($numerator, $denominator)
            + (($numerator % $denominator) * 2 >= $denominator ? 1 : 0);

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private function scaledDecimal(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return (int) $whole * (10 ** $scale) + (int) $fraction;
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $entries
     * @return array<int, list<int>>
     */
    private function equivalentCourseIdsByCourseId(Collection $entries): array
    {
        $courseIds = $entries
            ->pluck('courseSpecification.course_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        return $this->expandCourseIdEquivalencyMap($courseIds);
    }

    /**
     * @param  Collection<int, int>  $courseIds
     * @return array<int, list<int>>
     */
    private function expandCourseIdEquivalencyMap(Collection $courseIds): array
    {
        if ($courseIds->isEmpty()) {
            return [];
        }

        $map = [];
        foreach ($courseIds as $courseId) {
            $map[(int) $courseId] = [(int) $courseId];
        }

        $equivalencies = CourseRequirement::query()
            ->join('course_specifications', 'course_specifications.id', '=', 'course_requirements.course_specification_id')
            ->where('course_requirements.state', CourseRequirement::StateActive)
            ->where('course_requirements.rule_type', CourseRequirement::TypeEquivalency)
            ->where(function ($query) use ($courseIds): void {
                $query->whereIn('course_specifications.course_id', $courseIds)
                    ->orWhereIn('course_requirements.related_course_id', $courseIds);
            })
            ->get([
                'course_specifications.course_id as source_course_id',
                'course_requirements.related_course_id as related_course_id',
            ]);

        foreach ($equivalencies as $row) {
            $sourceId = (int) ($row->source_course_id ?? 0);
            $relatedId = (int) ($row->related_course_id ?? 0);
            if ($sourceId <= 0 || $relatedId <= 0) {
                continue;
            }

            if (isset($map[$sourceId]) && ! in_array($relatedId, $map[$sourceId], true)) {
                $map[$sourceId][] = $relatedId;
            }

            if (isset($map[$relatedId]) && ! in_array($sourceId, $map[$relatedId], true)) {
                $map[$relatedId][] = $sourceId;
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $matchingCourseIds
     * @param  Collection<int, GradeRosterRow>  $academicRows
     */
    private function resolveMatchingAcademicRow(array $matchingCourseIds, Collection $academicRows): ?GradeRosterRow
    {
        $candidates = collect($matchingCourseIds)
            ->map(fn (int $id): ?GradeRosterRow => $academicRows->get($id))
            ->filter(fn (?GradeRosterRow $row): bool => $row instanceof GradeRosterRow)
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->first(fn (GradeRosterRow $row): bool => $this->numericOutcomeMeets($row)
            || ($row->released_at !== null && $row->current_outcome_code === 'TC'))
            ?? $candidates->first();
    }

    /**
     * @param  list<int>  $matchingCourseIds
     * @param  Collection<int, PriorAcademicCredit>  $priorCredits
     */
    private function resolveMatchingPriorCredit(array $matchingCourseIds, Collection $priorCredits): ?PriorAcademicCredit
    {
        $candidates = collect($matchingCourseIds)
            ->map(fn (int $id): ?PriorAcademicCredit => $priorCredits->get($id))
            ->filter(fn (?PriorAcademicCredit $credit): bool => $credit instanceof PriorAcademicCredit)
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->first(fn (PriorAcademicCredit $credit): bool => $credit->isPassing())
            ?? $candidates->first();
    }

    /** @return Collection<int, int> */
    private function currentCourseIds(StudentProfile $studentProfile, ?Term $term): Collection
    {
        return CourseEnrollment::query()
            ->where('status', CourseEnrollment::StatusActive)
            ->whereHas('enrollment', fn ($query) => $query
                ->where('student_profile_id', $studentProfile->id)
                ->when($term, fn ($query) => $query->where('term_id', $term->id)))
            ->with('termOffering.curriculumEntry.courseSpecification')
            ->get()
            ->pluck('termOffering.curriculumEntry.courseSpecification.course_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
    }

    /** @return Collection<int, ProgramShiftCreditEntry> */
    private function acceptedShiftCredits(StudentProfile $studentProfile): Collection
    {
        return ProgramShiftCreditEntry::query()
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $studentProfile->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->keyBy('curriculum_entry_id');
    }

    /** @return Collection<int, EnrollmentException> */
    private function activeExceptions(StudentProfile $studentProfile, ?Term $term): Collection
    {
        return EnrollmentException::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('state', EnrollmentException::StateActive)
            ->when($term, fn ($query) => $query->where('term_id', $term->id))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->toBase();
    }

    /** @return Collection<int, TermOffering> */
    private function availableOfferings(EloquentCollection $entries, ?Term $term): Collection
    {
        if (! $term instanceof Term) {
            return collect();
        }

        return TermOffering::query()
            ->where('term_id', $term->id)
            ->whereIn('curriculum_entry_id', $entries->modelKeys())
            ->where('state', '!=', TermOffering::StateCancelled)
            ->orderByRaw("CASE WHEN category = 'REGULAR' THEN 0 ELSE 1 END")
            ->get()
            ->keyBy('curriculum_entry_id');
    }

    /** @return array{passed:bool,blockers:list<array<string,mixed>>} */
    private function requirementsPass(
        CurriculumEntry $entry,
        TermOffering $offering,
        Collection $rows,
        Collection $credits,
        Collection $priorCredits,
        Collection $currentCourseIds,
        Collection $exceptions,
    ): array {
        $requirements = $entry->courseSpecification->requirements
            ->whereIn('rule_type', [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite])
            ->where('state', CourseRequirement::StateActive)
            ->groupBy(fn (CourseRequirement $requirement): string => $requirement->rule_type.':'.$requirement->group_key);
        $blockers = [];

        foreach ($requirements as $groupKey => $alternatives) {
            $passed = $alternatives->contains(fn (CourseRequirement $requirement): bool => $this->requirementAlternativePasses($requirement, $rows, $credits, $priorCredits, $currentCourseIds));
            $exception = $exceptions->first(fn (EnrollmentException $item): bool => (int) $item->target_term_offering_id === (int) $offering->id
                && in_array($item->exception_type, [EnrollmentException::TypePrerequisite, EnrollmentException::TypeCorequisite], true)
                && in_array($item->original_rule, [$groupKey, $alternatives->first()?->group_key], true));

            if (! $passed && ! $exception instanceof EnrollmentException) {
                $blockers[] = [
                    'key' => $offering->id.':'.$groupKey,
                    'kind' => 'prerequisite',
                    'term_offering_id' => (int) $offering->id,
                    'course_id' => (int) $entry->courseSpecification->course_id,
                    'course_code' => $entry->courseSpecification->course->code,
                    'rule' => $groupKey,
                    'alternatives' => $alternatives->pluck('relatedCourse.code')->filter()->values()->all(),
                    'alternative_blockers' => $alternatives
                        ->map(fn ($requirement): array => $this->alternativeBlocker($requirement, $rows, $priorCredits))
                        ->values()
                        ->all(),
                ];
            }
        }

        return ['passed' => $blockers === [], 'blockers' => $blockers];
    }

    private function requirementAlternativePasses(
        CourseRequirement $requirement,
        Collection $rows,
        Collection $credits,
        Collection $priorCredits,
        Collection $currentCourseIds,
    ): bool {
        $relatedCourseId = (int) $requirement->related_course_id;
        $matchingCourseIds = $this->expandCourseIdEquivalencyMap(collect([$relatedCourseId]))[$relatedCourseId] ?? [$relatedCourseId];

        if ($requirement->rule_type === CourseRequirement::TypeCorequisite
            && collect($matchingCourseIds)->contains(fn (int $id): bool => $currentCourseIds->contains($id))) {
            return true;
        }

        $row = $this->resolveMatchingAcademicRow($matchingCourseIds, $rows);

        if ($row instanceof GradeRosterRow) {
            if ($row->released_at !== null && ($row->current_outcome_code === 'TC' || $this->numericOutcomeMeets($row))) {
                return true;
            }
        }

        if ($credits->contains(fn (ProgramShiftCreditEntry $credit): bool => in_array((int) $credit->source_course_id, $matchingCourseIds, true)
            && ($credit->numeric_grade === null || (float) $credit->numeric_grade <= 4.00))) {
            return true;
        }

        $priorCredit = $this->resolveMatchingPriorCredit($matchingCourseIds, $priorCredits);

        return $priorCredit instanceof PriorAcademicCredit && $priorCredit->isPassing();
    }

    private function requirementReason(CourseRequirement $requirement, Collection $rows, Collection $priorCredits): string
    {
        $row = $rows->get((int) $requirement->related_course_id);

        if ($row instanceof GradeRosterRow) {
            if ($row->released_at === null) {
                return 'pending_grade';
            }

            return match ($row->current_outcome_code) {
                'INC' => 'active_inc',
                default => 'failed',
            };
        }

        $priorCredit = $priorCredits->get((int) $requirement->related_course_id);

        if ($priorCredit instanceof PriorAcademicCredit) {
            if ($priorCredit->result_status === PriorAcademicCredit::ResultInc || $priorCredit->effectiveResultCode() === 'INC') {
                return 'active_inc';
            }

            if (! $priorCredit->isPassing()) {
                return 'failed';
            }
        }

        return 'missing_history';
    }

    /** @return array<string,mixed> */
    private function alternativeBlocker(mixed $requirement, Collection $rows, Collection $priorCredits): array
    {
        if (! $requirement instanceof CourseRequirement) {
            return ['course_id' => null, 'code' => null, 'reason' => self::class];
        }

        return [
            'course_id' => (int) $requirement->related_course_id,
            'code' => Course::query()->whereKey($requirement->related_course_id)->value('code'),
            'reason' => $this->requirementReason($requirement, $rows, $priorCredits),
        ];
    }

    private function numericOutcomeMeets(GradeRosterRow $row): bool
    {
        return $row->released_at !== null
            && $row->current_outcome_category === GradeRosterRow::CategoryPassing
            && is_numeric($row->current_outcome_code)
            && (float) $row->current_outcome_code <= 4.00;
    }

    private function completionSource(?GradeRosterRow $row, ?ProgramShiftCreditEntry $credit, ?PriorAcademicCredit $priorCredit = null): ?array
    {
        if ($credit instanceof ProgramShiftCreditEntry) {
            return ['type' => 'internal_shift_credit', 'id' => (int) $credit->id, 'numeric_grade' => $credit->numeric_grade];
        }

        if ($row instanceof GradeRosterRow) {
            if ($row->released_at !== null && $row->current_outcome_code === 'TC') {
                return ['type' => 'external_transfer_credit', 'id' => (int) $row->id];
            }

            if ($this->numericOutcomeMeets($row)) {
                return $this->rowSource($row);
            }
        }

        if ($priorCredit instanceof PriorAcademicCredit && $priorCredit->isPassing()) {
            return $this->priorCreditSource($priorCredit);
        }

        return null;
    }

    private function isBackSubject(?GradeRosterRow $row, StudentProfile $studentProfile, int $courseId, ?PriorAcademicCredit $priorCredit = null): bool
    {
        if ($row instanceof GradeRosterRow && $row->released_at !== null) {
            return in_array($row->current_outcome_category, [GradeRosterRow::CategoryFailed, GradeRosterRow::CategoryWithdrawn], true)
                || in_array($row->current_outcome_code, ['DRP', 'W'], true);
        }

        if (! $row instanceof GradeRosterRow
            && $priorCredit instanceof PriorAcademicCredit
            && in_array($priorCredit->result_status, [PriorAcademicCredit::ResultFailed, PriorAcademicCredit::ResultDropped], true)) {
            return true;
        }

        return CourseEnrollment::query()
            ->whereIn('status', ['dropped', 'withdrawn'])
            ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $studentProfile->id))
            ->whereHas('termOffering.curriculumEntry.courseSpecification', fn ($query) => $query->where('course_id', $courseId))
            ->exists();
    }

    /** @return array<string,mixed> */
    private function fact(CurriculumEntry $entry, string $status, ?array $source): array
    {
        return [
            'curriculum_entry_id' => (int) $entry->id,
            'course_id' => (int) $entry->courseSpecification->course_id,
            'course_code' => $entry->courseSpecification->course->code,
            'title' => $entry->courseSpecification->title,
            'units' => (string) $entry->courseSpecification->credit_units,
            'year_level' => $entry->year_level,
            'term_label' => $entry->term_label,
            'status' => $status,
            'source' => $source,
        ];
    }

    /** @return array<string,mixed>|null */
    private function rowSource(?GradeRosterRow $row): ?array
    {
        return $row instanceof GradeRosterRow ? [
            'type' => 'grade_roster_row', 'id' => (int) $row->id,
            'code' => $row->current_outcome_code, 'category' => $row->current_outcome_category,
        ] : null;
    }

    /** @return array<string,mixed>|null */
    private function priorCreditSource(?PriorAcademicCredit $priorCredit): ?array
    {
        return $priorCredit instanceof PriorAcademicCredit ? [
            'type' => 'prior_academic_credit',
            'id' => (int) $priorCredit->id,
            'code' => $priorCredit->effectiveResultCode(),
            'status' => $priorCredit->result_status,
        ] : null;
    }

    /** @return array<string,mixed>|null */
    private function outcomeBlocker(CurriculumEntry $entry, ?GradeRosterRow $row, ?PriorAcademicCredit $priorCredit = null): ?array
    {
        if ($row instanceof GradeRosterRow) {
            if ($row->released_at !== null && $row->current_outcome_code !== 'INC') {
                return null;
            }

            return [
                'key' => 'outcome:'.$entry->id.':'.($row->released_at === null ? 'unreleased' : $row->current_outcome_code),
                'kind' => 'outcome',
                'course_id' => (int) $entry->courseSpecification->course_id,
                'course_code' => $entry->courseSpecification->course->code,
                'reason' => $row->released_at === null ? 'pending_grade' : 'active_inc',
                'source' => $this->rowSource($row),
            ];
        }

        if ($priorCredit instanceof PriorAcademicCredit
            && ($priorCredit->result_status === PriorAcademicCredit::ResultInc || $priorCredit->effectiveResultCode() === 'INC')) {
            return [
                'key' => 'outcome:'.$entry->id.':prior:INC',
                'kind' => 'outcome',
                'course_id' => (int) $entry->courseSpecification->course_id,
                'course_code' => $entry->courseSpecification->course->code,
                'reason' => 'active_inc',
                'source' => $this->priorCreditSource($priorCredit),
            ];
        }

        return null;
    }

    private function recommendedStanding(StudentProfile $studentProfile, EloquentCollection $entries, array $completed, array $backSubjects, array $blockers, Collection $currentCourseIds): string
    {
        $authorized = [
            StudentProfile::StandingGraduationCandidate, StudentProfile::StandingCompletionCandidate,
            StudentProfile::StandingMustRepeatYear, StudentProfile::StandingProbationary,
        ];

        if (in_array($studentProfile->academic_standing, $authorized, true)) {
            return $studentProfile->academic_standing;
        }

        if (collect($blockers)->contains(fn (array $blocker): bool => ($blocker['kind'] ?? null) === 'prerequisite')) {
            return StudentProfile::StandingBlockedByPrerequisite;
        }
        if ($backSubjects !== [] || $blockers !== []) {
            return StudentProfile::StandingDeficient;
        }

        $requiredEntryIds = $entries
            ->where('requirement_group', CurriculumEntry::RequirementGroupRequired)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $completedRequiredCount = collect($completed)
            ->whereIn('curriculum_entry_id', $requiredEntryIds)
            ->count();

        if ($requiredEntryIds->isNotEmpty() && $completedRequiredCount === $requiredEntryIds->count()) {
            return StudentProfile::StandingCompletionCandidate;
        }

        $curriculumCourseIds = $entries->pluck('courseSpecification.course_id')->map(fn ($id): int => (int) $id);
        if ($currentCourseIds->diff($curriculumCourseIds)->isNotEmpty()) {
            return StudentProfile::StandingIrregular;
        }

        return StudentProfile::StandingRegular;
    }

    /**
     * @return array{available: bool, standing: ?string, label: string, explanation: string}
     */
    private function recommendationResult(StudentProfile $studentProfile, string $standing, bool $hasCurriculumBaseline = true): array
    {
        if (! $hasCurriculumBaseline) {
            return [
                'available' => false,
                'standing' => null,
                'label' => StudentProfile::StandingNotYetEvaluated,
                'explanation' => 'The system cannot evaluate progression until the curriculum baseline is available.',
            ];
        }

        $institutionalDecisionStandings = [
            StudentProfile::StandingGraduationCandidate,
            StudentProfile::StandingCompletionCandidate,
            StudentProfile::StandingMustRepeatYear,
            StudentProfile::StandingProbationary,
        ];

        if (in_array($studentProfile->academic_standing, $institutionalDecisionStandings, true)) {
            return [
                'available' => false,
                'standing' => null,
                'label' => 'Institutional review required',
                'explanation' => 'This official standing depends on an institutional decision, so the system does not present it as an independent recommendation.',
            ];
        }

        return [
            'available' => true,
            'standing' => $standing,
            'label' => self::standingLabel($standing),
            'explanation' => 'This system review is derived from released grades, curriculum requirements, and current academic blockers. It is not official until the Registrar records a decision.',
        ];
    }

    /** @return array<string,mixed> */
    private function missingBaselineResult(StudentProfile $studentProfile): array
    {
        return [
            'student_profile_id' => (int) $studentProfile->id,
            'curriculum_version_id' => $studentProfile->curriculum_version_id,
            'curriculum_length' => 0,
            'standing' => StudentProfile::StandingNotYetEvaluated,
            'authorized_standing' => $studentProfile->academic_standing,
            'recommendation' => $this->recommendationResult(
                $studentProfile,
                StudentProfile::StandingNotYetEvaluated,
                hasCurriculumBaseline: false,
            ),
            'completed' => [], 'back_subjects' => [], 'blockers' => [['key' => 'missing_curriculum_baseline']],
            'suggestions' => [], 'current_course_ids' => [],
            'gwa' => null,
            'facts' => ['required_count' => 0, 'completed_count' => 0, 'back_subject_count' => 0, 'blocker_count' => 1],
        ];
    }
}
