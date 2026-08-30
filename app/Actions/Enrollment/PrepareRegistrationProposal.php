<?php

namespace App\Actions\Enrollment;

use App\Models\Assessment;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\TermAccount;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
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

            if ($locked->selection_basis === Enrollment::SelectionStandardCurriculum) {
                $expectedOfferingIds = TermOffering::query()
                    ->where('term_id', $locked->term_id)
                    ->where('state', TermOffering::StateScheduled)
                    ->whereHas('curriculumEntry', fn ($query) => $query
                        ->where('curriculum_version_id', $curriculum->id))
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->sort()
                    ->values();
                $selectedOfferingIds = $sections
                    ->pluck('termOffering.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->sort()
                    ->values();

                if ($expectedOfferingIds->isEmpty() || $selectedOfferingIds->all() !== $expectedOfferingIds->all()) {
                    throw ValidationException::withMessages([
                        'sections' => 'Standard Curriculum requires the complete active exact-Term curriculum offering set. Use Individually Advised for an authorized bounded selection.',
                    ]);
                }
            }

            $unitLoad = $this->unitLoad->snapshotForSections(
                $locked,
                $sections->values(),
                $curriculumPosition,
            );

            $source = [
                'purpose' => $purpose,
                'term_id' => $locked->term_id,
                'curriculum_version_id' => $curriculum->id,
                'published_timetable_version_id' => $timetable->id,
                'sections' => $items,
                'unit_load' => $unitLoad,
            ];
            $hash = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
            $previous = $locked->currentProposalVersion()->lockForUpdate()->first();
            $version = ((int) $locked->proposalVersions()->max('version')) + 1;

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
