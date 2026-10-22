<?php

namespace App\Actions\Enrollment;

use App\Models\AdmissionApplication;
use App\Models\Assessment;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\TermAccount;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareRegistrationProposal
{
    public function __construct(
        private readonly RegistrationAcademicEligibilityQuery $eligibility,
        private readonly StudentUnitLoadService $unitLoad,
    ) {}

    /**
     * @param  list<int>  $sectionIds
     */
    public function execute(
        Enrollment $enrollment,
        User $actor,
        array $sectionIds,
        int $expectedLockVersion,
        string $purpose = RegistrationProposalVersion::PurposeInitial,
        ?string $curriculumPosition = null,
    ): RegistrationProposalVersion {
        $derivedStandard = (int) $enrollment->credential_user_id === (int) $actor->id
            && $enrollment->selection_basis === Enrollment::SelectionStandardCurriculum
            && $purpose === RegistrationProposalVersion::PurposeInitial;
        if ($derivedStandard && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            $expected = app(PrepareStandardRegistration::class)->sectionIds($enrollment);
            $selected = array_map('intval', $sectionIds);
            sort($expected);
            sort($selected);
            $derivedStandard = $expected === $selected;
        }
        if (! $actor->canAuthenticate()
            || (! $actor->hasRole(User::StaffRoleRegistrar) && ! $derivedStandard)) {
            throw new AuthorizationException('Only authorized Registrar staff may prepare a Registration Proposal.');
        }

        $sectionIds = collect($sectionIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();

        if ($sectionIds === []) {
            throw ValidationException::withMessages(['sections' => 'Select at least one exact-Term Class Offering.']);
        }

        if (! in_array($purpose, [RegistrationProposalVersion::PurposeInitial, RegistrationProposalVersion::PurposeAdjustment], true)) {
            throw ValidationException::withMessages(['purpose' => 'Select a supported Registration Proposal purpose.']);
        }

        return DB::transaction(function () use ($enrollment, $actor, $sectionIds, $expectedLockVersion, $purpose, $curriculumPosition): RegistrationProposalVersion {
            $locked = Enrollment::query()
                ->with(['admissionApplication', 'studentProfile'])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $expectedOutcome = $purpose === RegistrationProposalVersion::PurposeAdjustment
                ? Enrollment::OutcomeOfficiallyEnrolled
                : Enrollment::OutcomeInProgress;
            if ($locked->canonical_outcome !== $expectedOutcome || $locked->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages(['case' => 'The Registration Case changed. Refresh before preparing another proposal.']);
            }

            $timetable = PublishedTimetableVersion::query()
                ->where('term_id', $locked->term_id)
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if (! $timetable instanceof PublishedTimetableVersion) {
                throw ValidationException::withMessages(['timetable' => 'A current Published Timetable is required.']);
            }

            $sections = Section::query()
                ->with([
                    'termOffering.curriculumEntry.courseSpecification.course',
                    'termOffering.curriculumEntry.courseSpecification.components',
                ])
                ->whereIn('id', $sectionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sections->count() !== count($sectionIds)) {
                throw ValidationException::withMessages(['sections' => 'One or more Class Offerings do not exist.']);
            }

            $curriculumIds = collect();
            $items = [];

            foreach ($sectionIds as $sequence => $sectionId) {
                $section = $sections->get($sectionId);
                $offering = $section?->termOffering;
                $specification = $offering?->curriculumEntry?->courseSpecification;
                $course = $specification?->course;
                $meetings = PublishedTimetableMeeting::query()
                    ->with(['faculty', 'room'])
                    ->where('published_timetable_version_id', $timetable->id)
                    ->where('section_id', $sectionId)
                    ->orderBy('meeting_sequence')
                    ->get();

                $schedulingTreatment = $specification?->scheduling_treatment;
                $hasApprovedSchedule = match ($schedulingTreatment) {
                    CourseSpecification::SchedulingRecurring => $meetings->isNotEmpty(),
                    CourseSpecification::SchedulingExternallyArranged => $meetings->isEmpty(),
                    default => false,
                };

                if (! $section instanceof Section || ! $offering instanceof TermOffering
                    || (int) $offering->term_id !== (int) $locked->term_id
                    || $offering->state !== TermOffering::StateScheduled
                    || $section->state !== Section::StateOpen
                    || ! $hasApprovedSchedule || $specification === null || $course === null) {
                    throw ValidationException::withMessages(['sections' => 'Every item must be an active exact-Term Class Offering with its approved scheduling treatment in the current Published Timetable.']);
                }

                $curriculumIds->push((int) $offering->curriculumEntry->curriculum_version_id);
                $items[] = [
                    'sequence' => $sequence + 1,
                    'term_offering_id' => $offering->id,
                    'section_id' => $section->id,
                    'units_snapshot' => $specification->credit_units,
                    'course_code_snapshot' => $course->code,
                    'course_title_snapshot' => $specification->title,
                    'scheduling_treatment_snapshot' => $schedulingTreatment,
                    'contact_hours_snapshot' => [
                        'lecture' => number_format((float) $specification->components
                            ->where('component_type', CourseComponent::TypeLecture)
                            ->sum('weekly_contact_hours'), 2, '.', ''),
                        'laboratory' => number_format((float) $specification->components
                            ->where('component_type', CourseComponent::TypeLaboratory)
                            ->sum('weekly_contact_hours'), 2, '.', ''),
                    ],
                    'meeting_snapshot' => $meetings->map(fn ($meeting): array => [
                        'id' => $meeting->id,
                        'meeting_sequence' => $meeting->meeting_sequence,
                        'day_of_week' => $meeting->day_of_week,
                        'starts_at' => $meeting->starts_at,
                        'ends_at' => $meeting->ends_at,
                        'faculty_user_id' => $meeting->faculty_user_id,
                        'faculty_name' => $meeting->faculty?->getFilamentName(),
                        'room_id' => $meeting->room_id,
                        'room_label' => $meeting->room !== null ? $meeting->room->name : $meeting->location_label,
                        'modality' => $meeting->modality,
                    ])->all(),
                ];
            }

            if ($curriculumIds->unique()->count() !== 1) {
                throw ValidationException::withMessages(['sections' => 'All proposal items must use one authoritative Curriculum Version.']);
            }

            $curriculum = CurriculumVersion::query()->findOrFail($curriculumIds->first());
            $this->eligibility->assertEligible(
                $locked,
                $curriculum,
                $sections->pluck('termOffering')->filter()->values(),
            );

            $unitLoad = $this->unitLoad->snapshotForSections(
                $locked,
                $sections->values(),
                $curriculumPosition,
            );
            if ($locked->selection_basis === Enrollment::SelectionStandardCurriculum) {
                $selectedEntries = $sections->pluck('termOffering.curriculum_entry_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                if ($selectedEntries !== $unitLoad['curriculum_entry_ids']) {
                    throw ValidationException::withMessages(['sections' => 'Standard Curriculum requires the complete curriculum position, including required subjects that are not currently offered. Registrar must advise a bounded alternative when that block is unavailable.']);
                }
            }

            $source = [
                'purpose' => $purpose,
                'enrollment_id' => (int) $locked->id,
                'term_id' => $locked->term_id,
                'curriculum_version_id' => $curriculum->id,
                'published_timetable_version_id' => $timetable->id,
                'sections' => $items,
                'unit_load' => $unitLoad,
                ...($locked->admissionApplication?->application_path === AdmissionApplication::PathTransferee ? ['credit_evaluation_id' => RegistrationCreditEvaluation::latestCurrentFor($locked, $curriculum->id)?->id] : []),
            ];
            $previous = $locked->currentProposalVersion()->lockForUpdate()->first();
            if ($previous instanceof RegistrationProposalVersion && Arr::except($previous->source_snapshot, ['proposal_version']) == $source
                && in_array($previous->state, [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true)) {
                return $previous->load('items');
            }
            $version = ((int) $locked->proposalVersions()->max('version')) + 1;
            $source['proposal_version'] = $version;
            $hash = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));

            if ($previous instanceof RegistrationProposalVersion && $purpose === RegistrationProposalVersion::PurposeInitial) {
                EnrollmentSeatReservation::query()
                    ->where('enrollment_id', $locked->id)
                    ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                    ->whereHas('proposalItem', fn ($query) => $query->where('registration_proposal_version_id', $previous->id))
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (EnrollmentSeatReservation $reservation): bool => $reservation->update([
                        'status' => EnrollmentSeatReservation::StatusReleased,
                        'released_at' => now(),
                        'lock_version' => $reservation->lock_version + 1,
                    ]));
                Assessment::query()
                    ->where('enrollment_id', $locked->id)
                    ->where('source_proposal_version_id', $previous->id)
                    ->where('state', Assessment::StateActive)
                    ->lockForUpdate()
                    ->update(['state' => Assessment::StateSuperseded]);
                TermAccount::query()
                    ->where('enrollment_id', $locked->id)
                    ->lockForUpdate()
                    ->update(['state' => TermAccount::StateOpen]);
                $previous->update(['state' => RegistrationProposalVersion::StateSuperseded]);
            }

            if ($previous instanceof RegistrationProposalVersion && $purpose === RegistrationProposalVersion::PurposeAdjustment) {
                $previous->update(['state' => RegistrationProposalVersion::StateSuperseded]);
            }

            $proposal = RegistrationProposalVersion::query()->create([
                'enrollment_id' => $locked->id,
                'supersedes_version_id' => $previous?->id,
                'version' => $version,
                'state' => RegistrationProposalVersion::StateDraft,
                'purpose' => $purpose,
                'selection_basis' => $locked->selection_basis,
                'published_timetable_version_id' => $timetable->id,
                'curriculum_version_id' => $curriculum->id,
                'source_snapshot' => $source,
                'content_hash' => $hash,
                'prepared_by' => $actor->id,
                'prepared_at' => now(),
            ]);

            foreach ($items as $item) {
                RegistrationProposalItem::query()->create(['registration_proposal_version_id' => $proposal->id, ...$item]);
            }

            $locked->update(['current_proposal_version_id' => $proposal->id, 'lock_version' => $locked->lock_version + 1]);

            return $proposal->load('items');
        }, attempts: 3);
    }
}
