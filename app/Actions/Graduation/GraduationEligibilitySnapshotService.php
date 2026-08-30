<?php

namespace App\Actions\Graduation;

use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\EnrollmentException;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\Hold;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GraduationEligibilitySnapshotService
{
    public const ResultComplete = 'Complete';

    public const ResultReadyForRegistrarReview = 'Ready for Registrar Review';

    public const ResultBlockedMissingRequirement = 'Blocked: Missing Requirement';

    public const ResultBlockedFailedRequirement = 'Blocked: Failed Requirement';

    public const ResultBlockedPendingGrade = 'Blocked: Pending Grade';

    public const ResultBlockedInc = 'Blocked: INC';

    public const ResultBlockedHoldOrClearance = 'Blocked: Hold or Clearance';

    public const ResultBlockedCurrentEnrollmentNotFinalized = 'Blocked: Current Enrollment Not Finalized';

    public function __construct(private readonly HoldEvaluationService $holds) {}

    /** @return list<string> */
    public static function resultStatuses(): array
    {
        return [
            self::ResultComplete,
            self::ResultReadyForRegistrarReview,
            self::ResultBlockedMissingRequirement,
            self::ResultBlockedFailedRequirement,
            self::ResultBlockedPendingGrade,
            self::ResultBlockedInc,
            self::ResultBlockedHoldOrClearance,
            self::ResultBlockedCurrentEnrollmentNotFinalized,
        ];
    }

    public function generate(GraduationReviewMember $member, User $actor): GraduationSnapshot
    {
        Gate::forUser($actor)->authorize('refreshSnapshot', $member);

        return DB::transaction(function () use ($member, $actor): GraduationSnapshot {
            $locked = GraduationReviewMember::query()
                ->with(['studentProfile.program', 'studentProfile.curriculumVersion', 'latestSnapshot'])
                ->lockForUpdate()
                ->findOrFail($member->id);

            $payload = $this->payload($locked, $actor);
            $version = (int) GraduationSnapshot::query()
                ->where('graduation_review_member_id', $locked->id)
                ->lockForUpdate()
                ->max('version') + 1;

            $snapshot = GraduationSnapshot::query()->create([
                'graduation_review_member_id' => $locked->id,
                'version' => $version,
                'result_status' => $payload['result_status'],
                'evaluation_snapshot' => $payload,
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ]);

            activity()
                ->performedOn($snapshot)
                ->causedBy($actor)
                ->event('graduation_snapshot_generated')
                ->withProperties([
                    'graduation_review_member_id' => $locked->id,
                    'version' => $snapshot->version,
                    'result_status' => $snapshot->result_status,
                ])
                ->log('Graduation Eligibility Snapshot refreshed');

            return $snapshot;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function payload(GraduationReviewMember $member, User $actor): array
    {
        $profile = $member->studentProfile;
        $entries = $this->curriculumEntries($profile);
        $academicRows = $this->academicRows($profile);
        $currentEnrollments = $this->currentEnrollments($profile);
        $credits = $this->acceptedShiftCredits($profile);
        $exceptions = $this->activeGraduationExceptions($profile);
        $blockingHolds = $this->holds->activeBlockingHolds($profile, [
            Hold::BlockingGraduationEligibility,
            Hold::BlockingClearance,
            Hold::BlockingRecordRelease,
        ]);

        $completed = [];
        $current = [];
        $missing = [];
        $failed = [];
        $pending = [];
        $inc = [];
        $withdrawn = [];
        $acceptedCredits = [];
        $approvedExceptions = [];
        $sourceReferences = [];
        $remainingUnits = 0.0;

        foreach ($entries as $entry) {
            $sourceReferences[] = $this->reference('curriculum_entry', (int) $entry->id, $entry->courseSpecification?->course?->code);
            $courseId = (int) $entry->courseSpecification->course_id;
            $row = $academicRows->get($courseId);
            $credit = $credits->get((int) $entry->id);
            $exception = $exceptions->first(fn (EnrollmentException $item): bool => $item->original_rule === 'GRADUATION:'.$entry->id
                || $item->scope_key === 'graduation:'.$entry->id
                || $item->scope_key === 'completion-review');
            $fact = $this->entryFact($entry);

            if ($credit instanceof ProgramShiftCreditEntry) {
                $acceptedCredits[] = [
                    ...$fact,
                    'type' => 'internal_shift_credit',
                    'gwa_treatment' => $credit->numeric_grade === null ? 'excluded' : 'included',
                    'numeric_grade' => $credit->numeric_grade,
                    'source' => ['type' => 'program_shift_credit_entry', 'id' => (int) $credit->id],
                ];
                $sourceReferences[] = $this->reference('program_shift_credit_entry', (int) $credit->id, $entry->courseSpecification->course->code);
                $completed[] = [...$fact, 'status' => 'credited', 'source' => ['type' => 'program_shift_credit_entry', 'id' => (int) $credit->id]];

                continue;
            }

            if ($row instanceof GradeRosterRow && $row->current_outcome_code === 'TC') {
                $acceptedCredits[] = [
                    ...$fact,
                    'type' => 'external_transfer_credit',
                    'gwa_treatment' => 'excluded',
                    'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id],
                ];
                $sourceReferences[] = $this->reference('grade_roster_row', (int) $row->id, $entry->courseSpecification->course->code);
                $completed[] = [...$fact, 'status' => 'credited', 'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id]];

                continue;
            }

            if ($exception instanceof EnrollmentException) {
                $approvedExceptions[] = [
                    ...$fact,
                    'source' => ['type' => 'enrollment_exception', 'id' => (int) $exception->id],
                ];
                $sourceReferences[] = $this->reference('enrollment_exception', (int) $exception->id, $entry->courseSpecification->course->code);
                $completed[] = [...$fact, 'status' => 'cleared by approved Academic Exception', 'source' => ['type' => 'enrollment_exception', 'id' => (int) $exception->id]];

                continue;
            }

            if ($row instanceof GradeRosterRow) {
                $sourceReferences[] = $this->reference('grade_roster_row', (int) $row->id, $entry->courseSpecification->course->code);

                if ($row->released_at === null) {
                    $pending[] = [...$fact, 'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id]];

                    continue;
                }

                if ($row->current_outcome_category === GradeRosterRow::CategoryPassing && is_numeric($row->current_outcome_code)) {
                    $completed[] = [...$fact, 'status' => 'completed', 'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id]];

                    continue;
                }

                match ($row->current_outcome_category) {
                    GradeRosterRow::CategoryFailed => $failed[] = [...$fact, 'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id]],
                    GradeRosterRow::CategoryPending => $pending[] = [...$fact, 'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id]],
                    GradeRosterRow::CategoryIncomplete => $inc[] = [...$fact, 'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id]],
                    GradeRosterRow::CategoryWithdrawn => $withdrawn[] = [...$fact, 'source' => ['type' => 'grade_roster_row', 'id' => (int) $row->id]],
                    default => $missing[] = $fact,
                };
            } elseif ($currentEnrollments->has($courseId)) {
                $courseEnrollment = $currentEnrollments->get($courseId);
                $current[] = [
                    ...$fact,
                    'finalized' => $this->isFinalizedEnrollment($courseEnrollment),
                    'source' => ['type' => 'course_enrollment', 'id' => (int) $courseEnrollment->id],
                ];
                $sourceReferences[] = $this->reference('course_enrollment', (int) $courseEnrollment->id, $entry->courseSpecification->course->code);
            } else {
                $missing[] = $fact;
            }
        }

        $remainingUnits = collect([...$missing, ...$failed, ...$pending, ...$inc, ...$withdrawn, ...$current])
            ->sum(fn (array $item): float => (float) ($item['units'] ?? 0));
        $activeHolds = $blockingHolds
            ->whereIn('blocking_level', [Hold::BlockingGraduationEligibility, Hold::BlockingRecordRelease])
            ->map(fn (Hold $hold): array => $this->holdFact($hold))
            ->values()
            ->all();
        $clearanceBlockers = $blockingHolds
            ->where('blocking_level', Hold::BlockingClearance)
            ->map(fn (Hold $hold): array => $this->holdFact($hold))
            ->values()
            ->all();
        foreach ($blockingHolds as $hold) {
            $sourceReferences[] = $this->reference('hold', (int) $hold->id, $hold->blocking_level);
        }

        $currentPending = array_values(array_filter($current, static fn (array $item): bool => ! ($item['finalized'] ?? false)));

        $blockerGroups = $this->blockerGroups($activeHolds, $clearanceBlockers, $currentPending, $pending, $inc, $failed, $missing, $withdrawn);
        $result = $this->resultStatus($blockerGroups, $remainingUnits);

        return [
            'student' => [
                'id' => (int) $profile->id,
                'student_number' => $profile->student_number,
                'name' => trim($profile->first_name.' '.$profile->last_name),
            ],
            'program' => [
                'id' => $profile->program_id,
                'code' => $profile->program?->code,
                'name' => $profile->program?->name,
            ],
            'curriculum_version' => [
                'id' => $profile->curriculum_version_id,
                'name' => $profile->curriculumVersion->name ?? $profile->curriculumVersion->version_code,
            ],
            'generated' => [
                'at' => now()->toISOString(),
                'by' => $actor->name,
                'actor_user_id' => (int) $actor->id,
            ],
            'result_status' => $result,
            'blocker_groups' => $blockerGroups,
            'completed_requirements' => $completed,
            'current_enrollments' => $current,
            'missing_requirements' => $missing,
            'failed_requirements' => $failed,
            'pending_grade_requirements' => $pending,
            'inc_requirements' => $inc,
            'withdrawn_or_dropped_requirements' => $withdrawn,
            'accepted_credits' => $acceptedCredits,
            'approved_exceptions' => $approvedExceptions,
            'active_holds' => $activeHolds,
            'clearance_blockers' => $clearanceBlockers,
            'remaining_units' => (float) $remainingUnits,
            'source_references' => collect($sourceReferences)->unique(fn (array $reference): string => $reference['type'].':'.$reference['id'])->values()->all(),
            'student_projection' => $this->studentProjection($result, $missing, $withdrawn, $failed, $pending, $inc, $current, $activeHolds, $clearanceBlockers, $remainingUnits),
        ];
    }

    /** @return EloquentCollection<int, CurriculumEntry> */
    private function curriculumEntries(StudentProfile $profile): EloquentCollection
    {
        return CurriculumEntry::query()
            ->with(['courseSpecification.course'])
            ->where('curriculum_version_id', $profile->curriculum_version_id)
            ->orderBy('year_level')
            ->orderBy('term_label')
            ->orderBy('sequence')
            ->get();
    }

    /** @return Collection<int, GradeRosterRow> */
    private function academicRows(StudentProfile $profile): Collection
    {
        return GradeRosterRow::query()
            ->with('courseEnrollment.termOffering.curriculumEntry.courseSpecification.course')
            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('student_profile_id', $profile->id))
            ->latest('id')
            ->get()
            ->toBase()
            ->mapWithKeys(function (GradeRosterRow $row): array {
                $courseId = $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course_id;

                return $courseId === null ? [] : [(int) $courseId => $row];
            });
    }

    /** @return Collection<int, CourseEnrollment> */
    private function currentEnrollments(StudentProfile $profile): Collection
    {
        return CourseEnrollment::query()
            ->where('status', CourseEnrollment::StatusActive)
            ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $profile->id))
            ->with(['termOffering.curriculumEntry.courseSpecification', 'enrollment'])
            ->get()
            ->toBase()
            ->mapWithKeys(function (CourseEnrollment $enrollment): array {
                $courseId = $enrollment->termOffering?->curriculumEntry?->courseSpecification?->course_id;

                return $courseId === null ? [] : [(int) $courseId => $enrollment];
            });
    }

    /** @return Collection<int, ProgramShiftCreditEntry> */
    private function acceptedShiftCredits(StudentProfile $profile): Collection
    {
        return ProgramShiftCreditEntry::query()
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $profile->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->keyBy('curriculum_entry_id');
    }

    /** @return Collection<int, EnrollmentException> */
    private function activeGraduationExceptions(StudentProfile $profile): Collection
    {
        return EnrollmentException::query()
            ->where('student_profile_id', $profile->id)
            ->where('state', EnrollmentException::StateActive)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->toBase();
    }

    /** @return array<string, mixed> */
    private function entryFact(CurriculumEntry $entry): array
    {
        return [
            'curriculum_entry_id' => (int) $entry->id,
            'course_id' => (int) $entry->courseSpecification->course_id,
            'course_code' => $entry->courseSpecification->course->code,
            'title' => $entry->courseSpecification->title,
            'units' => (float) $entry->courseSpecification->credit_units,
            'year_level' => $entry->year_level,
            'term_label' => $entry->term_label,
        ];
    }

    /** @return array<string, mixed> */
    private function holdFact(Hold $hold): array
    {
        return [
            'id' => (int) $hold->id,
            'label' => str($hold->hold_type)->headline()->toString(),
            'blocking_level' => $hold->blocking_level,
            'student_message' => $hold->studentFacingMessage(),
            'office_to_contact' => $hold->studentFacingOfficeLabel(),
        ];
    }

    /** @return array<string, mixed> */
    private function reference(string $type, int $id, ?string $label): array
    {
        return ['type' => $type, 'id' => $id, 'label' => $label];
    }

    private function isFinalizedEnrollment(CourseEnrollment $courseEnrollment): bool
    {
        $enrollment = $courseEnrollment->enrollment;

        return $enrollment !== null
            && $enrollment->status === 'officially_enrolled'
            && $enrollment->officially_enrolled_at !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $activeHolds
     * @param  list<array<string, mixed>>  $clearanceBlockers
     * @param  list<array<string, mixed>>  $currentPending
     * @param  list<array<string, mixed>>  $pending
     * @param  list<array<string, mixed>>  $inc
     * @param  list<array<string, mixed>>  $failed
     * @param  list<array<string, mixed>>  $missing
     * @param  list<array<string, mixed>>  $withdrawn
     * @return list<array<string, mixed>>
     */
    private function blockerGroups(array $activeHolds, array $clearanceBlockers, array $currentPending, array $pending, array $inc, array $failed, array $missing, array $withdrawn): array
    {
        $groups = [];
        if ($activeHolds !== [] || $clearanceBlockers !== []) {
            $groups[] = ['key' => 'hold_or_clearance', 'label' => self::ResultBlockedHoldOrClearance, 'count' => count($activeHolds) + count($clearanceBlockers)];
        }
        if ($currentPending !== []) {
            $groups[] = ['key' => 'current_enrollment_not_finalized', 'label' => self::ResultBlockedCurrentEnrollmentNotFinalized, 'count' => count($currentPending)];
        }
        if ($pending !== []) {
            $groups[] = ['key' => 'pending_grade', 'label' => self::ResultBlockedPendingGrade, 'count' => count($pending)];
        }
        if ($inc !== []) {
            $groups[] = ['key' => 'inc_requirement', 'label' => self::ResultBlockedInc, 'count' => count($inc)];
        }
        if ($failed !== []) {
            $groups[] = ['key' => 'failed_requirement', 'label' => self::ResultBlockedFailedRequirement, 'count' => count($failed)];
        }
        if ($missing !== [] || $withdrawn !== []) {
            $groups[] = ['key' => 'missing_requirement', 'label' => self::ResultBlockedMissingRequirement, 'count' => count($missing) + count($withdrawn)];
        }

        return $groups;
    }

    private function resultStatus(array $blockerGroups, float $remainingUnits): string
    {
        $keys = collect($blockerGroups)->pluck('key');

        return match (true) {
            $keys->contains('hold_or_clearance') => self::ResultBlockedHoldOrClearance,
            $keys->contains('current_enrollment_not_finalized') => self::ResultBlockedCurrentEnrollmentNotFinalized,
            $keys->contains('pending_grade') => self::ResultBlockedPendingGrade,
            $keys->contains('inc_requirement') => self::ResultBlockedInc,
            $keys->contains('failed_requirement') => self::ResultBlockedFailedRequirement,
            $keys->contains('missing_requirement') => self::ResultBlockedMissingRequirement,
            $remainingUnits > 0 => self::ResultReadyForRegistrarReview,
            default => self::ResultComplete,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $missing
     * @param  list<array<string, mixed>>  $withdrawn
     * @param  list<array<string, mixed>>  $failed
     * @param  list<array<string, mixed>>  $pending
     * @param  list<array<string, mixed>>  $inc
     * @param  list<array<string, mixed>>  $currentRequirements
     * @param  list<array<string, mixed>>  $activeHolds
     * @param  list<array<string, mixed>>  $clearanceBlockers
     * @return array<string, mixed>
     */
    private function studentProjection(string $result, array $missing, array $withdrawn, array $failed, array $pending, array $inc, array $currentRequirements, array $activeHolds, array $clearanceBlockers, float $remainingUnits): array
    {
        $holdOrClearanceItems = collect([...$activeHolds, ...$clearanceBlockers])
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                'message' => $item['student_message'],
                'office' => $item['office_to_contact'],
            ])
            ->values();
        $label = static fn (array $item): string => $item['course_code'].' '.$item['title'];
        $statusLabel = match ($result) {
            self::ResultComplete => 'Requirements complete for Registrar review',
            self::ResultReadyForRegistrarReview => 'Current requirements in progress',
            default => str($result)->after('Blocked: ')->prepend('Review blocked: ')->toString(),
        };
        $requiredAction = match (true) {
            $result === self::ResultComplete => 'No action is required for this review. Wait for the Registrar to complete the official graduation process.',
            $holdOrClearanceItems->isNotEmpty() => 'Follow the listed hold or clearance instructions before asking the Registrar to refresh this review.',
            $currentRequirements !== [] && $pending === [] && $inc === [] && $failed === [] && $missing === [] && $withdrawn === [] => 'Continue the listed in-progress subjects and wait for their final grades.',
            default => 'Review the listed requirements and contact the Registrar if you need help with the next step.',
        };
        $officesToContact = $holdOrClearanceItems
            ->pluck('office')
            ->filter()
            ->unique()
            ->values();

        if ($officesToContact->isEmpty()) {
            $officesToContact->push('Registrar Office');
        }

        return [
            'result_status' => $result,
            'status_label' => $statusLabel,
            'remaining_units' => $remainingUnits,
            'remaining_requirements' => collect([...$missing, ...$withdrawn])->map($label)->values()->all(),
            'failed_requirements' => collect($failed)->map($label)->values()->all(),
            'in_progress_requirements' => collect($currentRequirements)->map($label)->values()->all(),
            'pending_grade_blockers' => collect($pending)->map($label)->values()->all(),
            'inc_blockers' => collect($inc)->map($label)->values()->all(),
            'hold_or_clearance_items' => $holdOrClearanceItems->all(),
            'hold_or_clearance_labels' => $holdOrClearanceItems->pluck('label')->all(),
            'required_action' => $requiredAction,
            'offices_to_contact' => $officesToContact->all(),
            'office_to_contact' => $officesToContact->implode(', '),
        ];
    }
}
