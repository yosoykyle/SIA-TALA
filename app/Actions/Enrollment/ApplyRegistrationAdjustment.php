<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Models\Assessment;
use App\Models\CorVersion;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment;
use App\Models\EnrollmentAdjustment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationLateAuthority;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyRegistrationAdjustment
{
    public function __construct(
        private readonly EnrollmentPaymentRequirementProjection $finance,
        private readonly CalendarPhaseGateService $calendar,
        private readonly RegistrationAcademicEligibilityQuery $eligibility,
        private readonly StudentUnitLoadService $unitLoad,
        private readonly RegistrationNotificationLedger $notifications,
    ) {}

    public function execute(
        Enrollment $enrollment,
        RegistrationProposalVersion $adjustmentProposal,
        User $actor,
        string $financialEffect,
        string $authorityReference,
        ?Assessment $successorAssessment = null,
        ?RegistrationLateAuthority $lateAuthority = null,
        ?RegistrationAdjustmentFinanceConfirmation $financialConfirmation = null,
    ): EnrollmentAdjustment {
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may apply an enrollment adjustment.');
        }
        if (! in_array($financialEffect, ['Increase', RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost], true)) {
            throw ValidationException::withMessages(['financial_effect' => 'Record the approved financial-effect classification.']);
        }

        $adjustment = DB::transaction(function () use ($enrollment, $adjustmentProposal, $actor, $financialEffect, $authorityReference, $successorAssessment, $lateAuthority, $financialConfirmation): EnrollmentAdjustment {
            $locked = Enrollment::query()
                ->with(['studentProfile.curriculumVersion', 'termAccount'])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $proposal = RegistrationProposalVersion::query()
                ->with(['items.termOffering.curriculumEntry.courseSpecification.course', 'confirmation'])
                ->whereKey($adjustmentProposal->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentCor = CorVersion::query()->whereKey($locked->current_cor_version_id)->lockForUpdate()->first();
            $timetable = PublishedTimetableVersion::query()
                ->where('term_id', $locked->term_id)
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->latest('version')
                ->lockForUpdate()
                ->first();
            $currentCourses = CourseEnrollment::query()
                ->with('termOffering.curriculumEntry.courseSpecification.course')
                ->where('enrollment_id', $locked->id)
                ->where('is_current', true)
                ->where('status', CourseEnrollment::StatusActive)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $late = $lateAuthority instanceof RegistrationLateAuthority
                ? RegistrationLateAuthority::query()->whereKey($lateAuthority->id)->lockForUpdate()->first()
                : null;
            $accountingConfirmation = $financialConfirmation instanceof RegistrationAdjustmentFinanceConfirmation
                ? RegistrationAdjustmentFinanceConfirmation::query()->whereKey($financialConfirmation->id)->lockForUpdate()->first()
                : null;

            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                || ! $currentCor instanceof CorVersion
                || ! $timetable instanceof PublishedTimetableVersion
                || (int) $proposal->enrollment_id !== (int) $locked->id
                || (int) $locked->current_proposal_version_id !== (int) $proposal->id
                || $proposal->purpose !== RegistrationProposalVersion::PurposeAdjustment
                || $proposal->state !== RegistrationProposalVersion::StateConfirmed
                || $proposal->confirmation === null
                || (int) $proposal->published_timetable_version_id !== (int) $timetable->id
                || blank($authorityReference)) {
                throw ValidationException::withMessages(['adjustment' => 'Adjustment requires one current learner-confirmed change proposal, official enrollment, and recorded authority.']);
            }

            $change = $this->deriveSingleChange($currentCourses, $proposal->items);
            $beforeCourse = $change['before'];
            $afterItem = $change['after'];
            $replacement = Section::query()
                ->with([
                    'termOffering.curriculumEntry.courseSpecification.course',
                    'termOffering.curriculumEntry.courseSpecification.components',
                ])
                ->whereKey($afterItem->section_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $late instanceof RegistrationLateAuthority) {
                $this->calendar->assertEnrollmentAdjustmentWindowOpen((int) $locked->term_id);
            }
            if ($late instanceof RegistrationLateAuthority
                && ((int) $late->enrollment_id !== (int) $locked->id
                    || (int) $late->term_id !== (int) $locked->term_id
                    || $late->action_type !== RegistrationLateAuthority::ActionAdjustment
                    || ($late->before_course_enrollment_id === null ? null : (int) $late->before_course_enrollment_id) !== ($beforeCourse?->id === null ? null : (int) $beforeCourse->id)
                    || (int) $late->after_section_id !== (int) $replacement->id
                    || $late->authority_reference !== $authorityReference
                    || $late->consumed_at !== null)) {
                throw ValidationException::withMessages(['late_authority' => 'The late authority is stale, already used, or does not match this exact add or replacement.']);
            }

            if ($replacement->state !== Section::StateOpen
                || (int) $replacement->termOffering->term_id !== (int) $locked->term_id
                || (int) $proposal->curriculum_version_id !== (int) $locked->studentProfile?->curriculum_version_id
                || $locked->studentProfile?->curriculumVersion === null) {
                throw ValidationException::withMessages(['section' => 'The adjustment must use a current exact-Term Class Offering from the Student’s active curriculum.']);
            }

            /** @var EloquentCollection<int, TermOffering> $offerings */
            $offerings = $proposal->items->pluck('termOffering')->filter()->values();
            $this->eligibility->assertEligible($locked, $locked->studentProfile->curriculumVersion, $offerings);
            $this->unitLoad->assertProposalPermitted($locked, $proposal, lockForUpdate: true);

            $replacementMeetings = PublishedTimetableMeeting::query()
                ->with(['faculty', 'room'])
                ->where('published_timetable_version_id', $timetable->id)
                ->where('section_id', $replacement->id)
                ->orderBy('meeting_sequence')
                ->lockForUpdate()
                ->get();
            $replacementSpecification = $replacement->termOffering->curriculumEntry->courseSpecification;
            $hasApprovedSchedule = match ($replacementSpecification->scheduling_treatment) {
                CourseSpecification::SchedulingRecurring => $replacementMeetings->isNotEmpty(),
                CourseSpecification::SchedulingExternallyArranged => $replacementMeetings->isEmpty(),
                default => false,
            };
            if (! $hasApprovedSchedule) {
                throw ValidationException::withMessages(['section' => 'The adjustment must use the approved recurring or no-meeting treatment from the current Published Timetable.']);
            }

            $occupiedQuery = CourseEnrollment::query()
                ->where('section_id', $replacement->id)
                ->where('is_current', true)
                ->where('status', CourseEnrollment::StatusActive);
            if ($beforeCourse instanceof CourseEnrollment) {
                $occupiedQuery->whereKeyNot($beforeCourse->id);
            }
            $occupied = $occupiedQuery->lockForUpdate()->count();
            $held = EnrollmentSeatReservation::query()
                ->where('section_id', $replacement->id)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->lockForUpdate()
                ->count();
            if ($occupied + $held >= (int) $replacement->capacity) {
                throw ValidationException::withMessages(['section' => 'The selected Class Offering has no available capacity.']);
            }

            $otherSectionIds = $currentCourses
                ->reject(fn (CourseEnrollment $course): bool => $beforeCourse instanceof CourseEnrollment && $course->is($beforeCourse))
                ->pluck('section_id');
            $otherMeetings = PublishedTimetableMeeting::query()
                ->where('published_timetable_version_id', $timetable->id)
                ->whereIn('section_id', $otherSectionIds)
                ->get();
            foreach ($replacementMeetings as $meeting) {
                if ($otherMeetings->contains(fn (PublishedTimetableMeeting $other): bool => $meeting->day_of_week === $other->day_of_week
                    && $meeting->starts_at < $other->ends_at
                    && $other->starts_at < $meeting->ends_at)) {
                    throw ValidationException::withMessages(['section' => 'The selected Class Offering conflicts with another current official course.']);
                }
            }

            $financeProjection = $financialEffect === 'Increase' ? $this->finance->forEnrollment($locked) : null;
            if ($financialEffect === 'Increase'
                && (! $successorAssessment instanceof Assessment
                    || (int) $successorAssessment->id === (int) $currentCor->assessment_id
                    || (int) $successorAssessment->enrollment_id !== (int) $locked->id
                    || (int) $successorAssessment->term_account_id !== (int) $locked->termAccount?->id
                    || (int) $successorAssessment->source_proposal_version_id !== (int) $proposal->id
                    || $successorAssessment->state !== Assessment::StateActive
                    || (int) ($financeProjection['assessment_id'] ?? 0) !== (int) $successorAssessment->id
                    || ($financeProjection['state'] ?? null) !== 'Cleared')) {
                throw ValidationException::withMessages(['assessment' => 'A cost increase requires a cleared successor assessment tied to this exact adjustment proposal.']);
            }
            if ($financialEffect === RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost
                && (! $accountingConfirmation instanceof RegistrationAdjustmentFinanceConfirmation
                    || (int) $accountingConfirmation->enrollment_id !== (int) $locked->id
                    || ($accountingConfirmation->current_course_enrollment_id === null ? null : (int) $accountingConfirmation->current_course_enrollment_id) !== ($beforeCourse?->id === null ? null : (int) $beforeCourse->id)
                    || (int) $accountingConfirmation->replacement_section_id !== (int) $replacement->id
                    || $accountingConfirmation->financial_effect !== RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost
                    || $accountingConfirmation->consumed_at !== null)) {
                throw ValidationException::withMessages(['financial_confirmation' => 'No-additional-cost adjustment requires the matching unused Accounting confirmation.']);
            }

            $specification = $replacement->termOffering->curriculumEntry->courseSpecification;
            $course = $specification->course;
            $successorCourse = CourseEnrollment::query()->create([
                'enrollment_id' => $locked->id,
                'term_offering_id' => $replacement->term_offering_id,
                'section_id' => $replacement->id,
                'registration_proposal_item_id' => $afterItem->id,
                'published_timetable_version_id' => $timetable->id,
                'supersedes_course_enrollment_id' => $beforeCourse?->id,
                'change_source' => $late instanceof RegistrationLateAuthority ? 'LateAuthorizedAdjustment' : 'EnrollmentAdjustment',
                'effective_from' => now(),
                'is_current' => true,
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => $specification->credit_units,
                'added_at' => now(),
                'status_reason' => 'Created from the exact learner-confirmed Registration Adjustment proposal.',
            ]);
            $beforeCourse?->update([
                'is_current' => false,
                'effective_until' => now(),
                'status_reason' => 'Superseded by Registration Adjustment.',
            ]);

            $changeSnapshot = [
                'type' => $change['type'],
                'adjustment_proposal_version_id' => $proposal->id,
                'learner_confirmation_id' => $proposal->confirmation->id,
                'learner_confirmation_method' => $proposal->confirmation->method,
                'learner_confirmation_evidence' => $proposal->confirmation->assisted_evidence_reference,
                'from_course_enrollment_id' => $beforeCourse?->id,
                'to_course_enrollment_id' => $successorCourse->id,
                'from_section_id' => $beforeCourse?->section_id,
                'to_section_id' => $replacement->id,
                'published_timetable_version_id' => $timetable->id,
                'late_authority_id' => $late?->id,
                'financial_confirmation_id' => $accountingConfirmation?->id,
            ];
            $adjustment = EnrollmentAdjustment::query()->create([
                'enrollment_id' => $locked->id,
                'supersedes_cor_version_id' => $currentCor->id,
                'authority_reference' => trim($authorityReference),
                'financial_effect' => $financialEffect,
                'change_snapshot' => $changeSnapshot,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            if ($accountingConfirmation instanceof RegistrationAdjustmentFinanceConfirmation) {
                $accountingConfirmation->update(['enrollment_adjustment_id' => $adjustment->id, 'consumed_at' => now()]);
            }
            $late?->update(['consumed_at' => now()]);
            RegistrationCaseEvent::query()->create([
                'enrollment_id' => $locked->id,
                'sequence' => ((int) $locked->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => $late instanceof RegistrationLateAuthority ? 'LateAuthorizedRegistrationAdjustment' : 'RegistrationAdjustment',
                'from_outcome' => $locked->canonical_outcome,
                'to_outcome' => $locked->canonical_outcome,
                'reason' => $change['type'].' applied from learner-confirmed proposal version '.$proposal->version.'.',
                'authority_reference' => trim($authorityReference),
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);

            $replacementSnapshot = [
                'course_enrollment_id' => $successorCourse->id,
                'proposal_item_id' => $afterItem->id,
                'section_id' => $replacement->id,
                'section_code' => $replacement->code,
                'course_code' => $course->code,
                'course_title' => $specification->title,
                'units' => $specification->credit_units,
                'scheduling_treatment' => $specification->scheduling_treatment,
                'contact_hours' => [
                    'lecture' => number_format((float) $specification->components
                        ->where('component_type', CourseComponent::TypeLecture)
                        ->sum('weekly_contact_hours'), 2, '.', ''),
                    'laboratory' => number_format((float) $specification->components
                        ->where('component_type', CourseComponent::TypeLaboratory)
                        ->sum('weekly_contact_hours'), 2, '.', ''),
                ],
                'meetings' => $replacementMeetings->map(fn (PublishedTimetableMeeting $meeting): array => [
                    'day_of_week' => $meeting->day_of_week,
                    'starts_at' => $meeting->starts_at,
                    'ends_at' => $meeting->ends_at,
                    'modality' => $meeting->modality,
                    'room_label' => $meeting->room_id !== null ? $meeting->room->name : $meeting->location_label,
                    'faculty_user_id' => $meeting->faculty_user_id,
                    'faculty_name' => $meeting->faculty?->getFilamentName(),
                    'room_id' => $meeting->room_id,
                ])->all(),
            ];
            $snapshot = $currentCor->snapshot;
            $courses = collect($snapshot['courses'] ?? []);
            $snapshot['courses'] = $beforeCourse instanceof CourseEnrollment
                ? $courses->map(fn (array $row): array => (int) $row['course_enrollment_id'] === (int) $beforeCourse->id ? $replacementSnapshot : $row)->values()->all()
                : $courses->push($replacementSnapshot)->values()->all();
            $snapshot['change'] = ['type' => $change['type'], 'record_id' => $adjustment->id, 'financial_effect' => $financialEffect, 'details' => $changeSnapshot];
            $assessmentId = $successorAssessment instanceof Assessment ? $successorAssessment->id : $currentCor->assessment_id;
            $snapshot['assessment_id'] = $assessmentId;
            $snapshot['published_timetable_version_id'] = $timetable->id;
            $snapshot['issued_by_user_id'] = $actor->id;
            $snapshot['issued_by_name'] = $actor->getFilamentName();
            $snapshot['issued_at'] = now()->toIso8601String();
            $successor = CorVersion::query()->create([
                'enrollment_id' => $locked->id,
                'supersedes_version_id' => $currentCor->id,
                'version' => $currentCor->version + 1,
                'registration_proposal_version_id' => $proposal->id,
                'assessment_id' => $assessmentId,
                'published_timetable_version_id' => $timetable->id,
                'snapshot' => $snapshot,
                'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);
            $locked->update(['current_cor_version_id' => $successor->id, 'lock_version' => $locked->lock_version + 1]);

            return $adjustment;
        }, attempts: 3);
        $this->notifications->recordAdjustment($adjustment);

        return $adjustment;
    }

    /**
     * @param  EloquentCollection<int, CourseEnrollment>  $currentCourses
     * @param  EloquentCollection<int, RegistrationProposalItem>  $proposalItems
     * @return array{type:string,before:CourseEnrollment|null,after:RegistrationProposalItem}
     */
    private function deriveSingleChange(EloquentCollection $currentCourses, EloquentCollection $proposalItems): array
    {
        $currentByCourse = $currentCourses->keyBy(fn (CourseEnrollment $item): int => (int) $item->termOffering?->curriculumEntry?->courseSpecification?->course_id);
        $proposalByCourse = $proposalItems->keyBy(fn (RegistrationProposalItem $item): int => (int) $item->termOffering?->curriculumEntry?->courseSpecification?->course_id);
        if ($currentByCourse->has(0) || $proposalByCourse->has(0)
            || $currentByCourse->count() !== $currentCourses->count()
            || $proposalByCourse->count() !== $proposalItems->count()) {
            throw ValidationException::withMessages(['adjustment' => 'The adjustment proposal contains duplicate or unresolved Course authority.']);
        }

        $removed = $currentByCourse->diffKeys($proposalByCourse)->values();
        $added = $proposalByCourse->diffKeys($currentByCourse)->values();
        $changed = $proposalByCourse->intersectByKeys($currentByCourse)
            ->filter(fn (RegistrationProposalItem $item, int $courseId): bool => (int) $item->section_id !== (int) $currentByCourse->get($courseId)->section_id)
            ->values();

        if ($removed->isEmpty() && $added->count() === 1 && $changed->isEmpty()) {
            return ['type' => 'Add', 'before' => null, 'after' => $added->sole()];
        }
        if ($removed->count() === 1 && $added->count() === 1 && $changed->isEmpty()) {
            return ['type' => 'Replace', 'before' => $removed->sole(), 'after' => $added->sole()];
        }
        if ($removed->isEmpty() && $added->isEmpty() && $changed->count() === 1) {
            $after = $changed->sole();
            $courseId = (int) $after->termOffering?->curriculumEntry?->courseSpecification?->course_id;

            return ['type' => 'ClassChange', 'before' => $currentByCourse->get($courseId), 'after' => $after];
        }

        throw ValidationException::withMessages(['adjustment' => 'One Adjustment proposal may contain exactly one Add, Replace, or class change.']);
    }
}
