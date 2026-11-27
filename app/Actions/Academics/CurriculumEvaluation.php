<?php

namespace App\Actions\Academics;

use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\PriorAcademicCredit;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationCreditLine;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class CurriculumEvaluation
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /** @return array{required: list<array<string, mixed>>, completed_units: string, deficiency_count: int} */
    public function forStudent(StudentProfile $student): array
    {
        $attempts = $this->results->forStudent($student);
        $acceptedCredits = ProgramShiftCreditEntry::query()
            ->with('curriculumEntry.courseSpecification')
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->where('state', ProgramShiftCreditEntry::StateRecorded)
            ->whereHas('curriculumEntry', fn ($query) => $query->where('curriculum_version_id', $student->curriculum_version_id))
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->filter(fn (ProgramShiftCreditEntry $credit): bool => $credit->numeric_grade === null || (float) $credit->numeric_grade <= 4.00)
            ->keyBy('curriculum_entry_id');
        $transferCredits = RegistrationCreditEvaluation::creditedEntryIdsForStudent($student);
        // The TOR source of each transfer credit, for a clear "credited" indicator (#99).
        $transferLines = RegistrationCreditEvaluation::effectiveForStudent($student)?->creditLines()->get()->keyBy('curriculum_entry_id') ?? collect();
        $acceptedCredits->loadMissing('sourceCourse');
        $entries = CurriculumEntry::query()
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->with(['courseSpecification.course', 'courseSpecification.requirements.relatedCourse'])
            ->orderBy('sequence')
            ->get();
        $equivalentCourseIdsByCourseId = $this->equivalentCourseIdsByCourseId($entries);

        $rows = $entries
            ->map(function (CurriculumEntry $entry) use ($attempts, $acceptedCredits, $transferCredits, $transferLines, $equivalentCourseIdsByCourseId): array {
                $courseId = $entry->courseSpecification?->course_id;
                $matchingCourseIds = $courseId !== null
                    ? ($equivalentCourseIdsByCourseId[(int) $courseId] ?? [(int) $courseId])
                    : [];
                $entryAttempts = $attempts->filter(fn (array $attempt): bool => $courseId !== null
                    ? in_array((int) ($attempt['course_specification']?->course_id ?? 0), $matchingCourseIds, true)
                    : (int) $attempt['course_specification']?->id === (int) $entry->course_specification_id);
                $passingAttempts = $entryAttempts->filter(
                    fn (array $attempt): bool => RegistrationAcademicEligibilityQuery::isPassingResult($attempt['result'] ?? null),
                );
                $passed = $passingAttempts->isNotEmpty();
                $passedInTala = $passingAttempts->contains(
                    fn (array $attempt): bool => $attempt['event'] !== null
                        || ($attempt['course_enrollment'] instanceof CourseEnrollment && ! (($attempt['prior_credit'] ?? null) instanceof PriorAcademicCredit)),
                );
                $passingPriorCredit = $passingAttempts
                    ->filter(fn (array $attempt): bool => ($attempt['prior_credit'] ?? null) instanceof PriorAcademicCredit)
                    ->map(fn (array $attempt): PriorAcademicCredit => $attempt['prior_credit'])
                    ->last();
                $isExternalTransferPrior = $passingPriorCredit instanceof PriorAcademicCredit
                    && self::isExternalTransferPriorCredit($passingPriorCredit);
                $isPreTalaPrior = $passingPriorCredit instanceof PriorAcademicCredit && ! $isExternalTransferPrior;
                $creditedViaEvaluationOrShift = $acceptedCredits->has($entry->id) || in_array((int) $entry->id, $transferCredits, true);
                $credited = $creditedViaEvaluationOrShift || (! $passedInTala && $isExternalTransferPrior);
                $passedPreTala = ! $creditedViaEvaluationOrShift && ! $passedInTala && $isPreTalaPrior;
                $inProgress = $entryAttempts->contains(fn (array $attempt): bool => $attempt['event'] === null
                    && $attempt['course_enrollment'] instanceof CourseEnrollment
                    && $attempt['course_enrollment']->is_current
                    && $attempt['course_enrollment']->status === CourseEnrollment::StatusActive);

                $latestUnpassed = $passed || $credited
                    ? null
                    : $entryAttempts->filter(fn (array $attempt): bool => $attempt['event'] !== null
                        || ($attempt['prior_credit'] ?? null) instanceof PriorAcademicCredit
                        || in_array($attempt['result'] ?? null, ['DRP', 'W'], true))->last();
                $latestResult = $latestUnpassed['result'] ?? null;

                $creditMetadata = match (true) {
                    $creditedViaEvaluationOrShift => self::creditSource($entry, $acceptedCredits->get($entry->id), $transferLines->get($entry->id)),
                    ! $passedInTala && $isExternalTransferPrior && $passingPriorCredit instanceof PriorAcademicCredit => self::priorCreditSource($passingPriorCredit, externalTransfer: true),
                    $passedPreTala && $passingPriorCredit instanceof PriorAcademicCredit => self::priorCreditSource($passingPriorCredit, externalTransfer: false),
                    default => null,
                };

                $recordSource = match (true) {
                    $acceptedCredits->has($entry->id) => 'program_shift',
                    in_array((int) $entry->id, $transferCredits, true) => 'external_transfer',
                    $passedInTala => 'sia_tala',
                    $isExternalTransferPrior => 'external_transfer',
                    $isPreTalaPrior => 'pre_tala',
                    default => null,
                };

                return [
                    'curriculum_entry' => $entry,
                    'checklist_state' => match (true) {
                        $credited => 'credited',
                        $passedInTala => 'passed',
                        $passedPreTala => 'pre_tala',
                        $passed => 'passed',
                        $inProgress => 'in_progress',
                        is_numeric($latestResult) && (float) $latestResult > 4.00 => 'failed',
                        $latestResult === 'INC' => 'incomplete',
                        in_array($latestResult, ['DRP', 'W'], true) => 'dropped',
                        default => 'not_taken',
                    },
                    'record_source' => $recordSource,
                    'course_id' => $courseId !== null ? (int) $courseId : null,
                    'status' => match (true) {
                        $creditedViaEvaluationOrShift => 'Approved credit',
                        $passed => 'Completed',
                        $inProgress => 'In progress',
                        $entryAttempts->isEmpty() => 'Not taken',
                        default => 'Deficient',
                    },
                    'credit' => $creditMetadata,
                    // #110 item 17: the result that decides this row (passing attempt, else the latest result) for the grade column and averages.
                    'grade_attempt' => $passingAttempts->last() ?? $latestUnpassed,
                    'grade' => self::displayGrade($passingAttempts->last() ?? $latestUnpassed),
                    'attempt_count' => $entryAttempts->count(),
                    'credited_units' => $passed || $credited ? (string) $entry->courseSpecification->credit_units : '0.00',
                    'requirements' => $entry->courseSpecification->requirements->map(fn ($requirement): array => [
                        'type' => $requirement->rule_type,
                        'related_course_id' => $requirement->related_course_id,
                        'related_course_code' => $requirement->relatedCourse?->code,
                    ])->values()->all(),
                ];
            });

        $rows = $this->withPrerequisiteLocks($rows);

        return [
            'required' => $rows->values()->all(),
            'checklist_totals' => [
                'total' => $rows->count(),
                'passed' => $rows->where('checklist_state', 'passed')->count(),
                'pre_tala' => $rows->where('checklist_state', 'pre_tala')->count(),
                'credited' => $rows->where('checklist_state', 'credited')->count(),
                'failed' => $rows->where('checklist_state', 'failed')->count(),
                'dropped' => $rows->whereIn('checklist_state', ['dropped', 'incomplete'])->count(),
                'in_progress' => $rows->where('checklist_state', 'in_progress')->count(),
                'not_taken' => $rows->whereIn('checklist_state', ['not_taken', 'locked'])->count(),
                'remaining' => $rows->whereNotIn('checklist_state', ['passed', 'pre_tala', 'credited'])->count(),
            ],
            'completed_units' => number_format($rows->sum(fn (array $row): float => (float) $row['credited_units']), 2, '.', ''),
            'deficiency_count' => $rows->reject(fn (array $row): bool => in_array($row['status'], ['Completed', 'Approved credit'], true))->count(),
        ];
    }

    /**
     * Registrar-facing grade for a curriculum row: a numeric final grade to two decimals, or the recorded result code.
     *
     * @param  array<string, mixed>|null  $attempt
     */
    public static function displayGrade(?array $attempt): ?string
    {
        $result = $attempt['result'] ?? null;

        if ($result === null || $result === '') {
            return null;
        }

        return is_numeric($result) ? number_format((float) $result, 2, '.', '') : (string) $result;
    }

    /**
     * Distinguishes external transfer prior credits from Servitech pre-TALA records.
     * Safely supports both schemas before and after `is_external_transfer` is migrated.
     */
    public static function isExternalTransferPriorCredit(PriorAcademicCredit $credit): bool
    {
        if ((bool) ($credit->getAttribute('is_external_transfer') ?? false)) {
            return true;
        }

        if (strtoupper(trim((string) ($credit->final_grade ?? ''))) === 'TC') {
            return true;
        }

        $sourceSchool = trim((string) ($credit->source_school ?? ''));
        if ($sourceSchool === '') {
            return false;
        }

        $normalized = strtolower($sourceSchool);
        if (str_contains($normalized, 'servitech') || in_array($normalized, ['sia', 'sia-tala'], true)) {
            return false;
        }

        return true;
    }

    public static function priorCreditSourceKind(PriorAcademicCredit $credit): string
    {
        return self::isExternalTransferPriorCredit($credit) ? 'external_transfer' : 'pre_tala';
    }

    /**
     * @return array{kind: string, label: string, detail: ?string}
     */
    private static function priorCreditSource(PriorAcademicCredit $credit, bool $externalTransfer): array
    {
        $resultCode = $credit->effectiveResultCode();

        if ($externalTransfer) {
            return [
                'kind' => 'transfer',
                'label' => 'Transfer credit (External)',
                'detail' => collect([
                    filled($credit->source_school) ? 'From '.$credit->source_school : null,
                    $resultCode === 'TC' ? 'TOR transfer credit (TC)' : (is_numeric($resultCode) ? 'Grade '.$resultCode : null),
                    $credit->displayAcademicYearLabel() !== 'Prior Institutional Record' ? $credit->displayAcademicYearLabel() : null,
                ])->filter()->implode(' · ') ?: 'External transfer credit',
            ];
        }

        return [
            'kind' => 'pre_tala',
            'label' => 'Pre-TALA Servitech record',
            'detail' => collect([
                $credit->displayAcademicYearLabel() !== 'Prior Institutional Record' ? $credit->displayAcademicYearLabel() : null,
                $credit->displayTermLabel() !== 'Prior Term' ? $credit->displayTermLabel() : null,
                is_numeric($resultCode) ? 'Grade '.$resultCode : ($resultCode === 'CREDIT' ? 'Recorded as credited' : null),
            ])->filter()->implode(' · ') ?: null,
        ];
    }

    /**
     * Where a credited subject came from: a transfer credit (TOR course and grade) or a program-shift credit.
     *
     * @return array{kind: string, label: string, detail: ?string}
     */
    private static function creditSource(CurriculumEntry $entry, ?ProgramShiftCreditEntry $shift, ?RegistrationCreditLine $line): array
    {
        if ($shift !== null) {
            return [
                'kind' => 'shift',
                'label' => 'Credited (program shift)',
                'detail' => collect([
                    $shift->sourceCourse?->code !== null ? 'From '.$shift->sourceCourse->code : null,
                    $shift->numeric_grade !== null ? 'Grade '.rtrim(rtrim(number_format((float) $shift->numeric_grade, 2), '0'), '.') : null,
                ])->filter()->implode(' · ') ?: null,
            ];
        }

        return [
            'kind' => 'transfer',
            'label' => 'Credited (transfer)',
            'detail' => $line === null ? null : collect([
                'From '.trim($line->source_course_code.' '.($line->source_course_title !== $entry->courseSpecification?->title ? '– '.$line->source_course_title : '')),
                filled($line->tor_grade) ? 'TOR grade '.$line->tor_grade : null,
            ])->filter()->implode(' · '),
        ];
    }

    /**
     * Marks not-yet-taken subjects whose prerequisite was attempted but not passed (#99 Scope 11).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withPrerequisiteLocks(Collection $rows): Collection
    {
        $unpassed = $rows->whereIn('checklist_state', ['failed', 'incomplete', 'dropped'])
            ->mapWithKeys(fn (array $row): array => [(int) $row['course_id'] => (string) $row['curriculum_entry']->courseSpecification?->course?->code]);

        return $rows->map(function (array $row) use ($unpassed): array {
            $blockedBy = $row['checklist_state'] === 'not_taken'
                ? collect($row['requirements'])
                    ->where('type', CourseRequirement::TypePrerequisite)
                    ->filter(fn (array $requirement): bool => $unpassed->has((int) $requirement['related_course_id']))
                    ->map(fn (array $requirement): string => (string) ($requirement['related_course_code'] ?? $unpassed->get((int) $requirement['related_course_id'])))
                    ->values()
                : collect();

            return [
                ...$row,
                'checklist_state' => $blockedBy->isNotEmpty() ? 'locked' : $row['checklist_state'],
                'locked_by' => $blockedBy->all(),
            ];
        });
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

        if ($courseIds->isEmpty()) {
            return [];
        }

        $map = [];
        foreach ($courseIds as $courseId) {
            $map[$courseId] = [$courseId];
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
}
