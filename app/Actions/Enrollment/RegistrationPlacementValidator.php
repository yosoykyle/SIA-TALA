<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RegistrationPlacementValidator
{
    public function __construct(
        private readonly RegistrationAcademicEligibilityQuery $academicEligibility,
        private readonly StudentUnitLoadService $unitLoad,
    ) {}

    public function assertCurrent(
        Enrollment $enrollment,
        RegistrationProposalVersion $proposal,
        bool $lockForUpdate = false,
    ): void {
        $proposal->loadMissing([
            'curriculumVersion',
            'items.reservation',
            'items.section.termOffering.curriculumEntry.courseSpecification.course',
        ]);

        if ($enrollment->canonical_outcome !== Enrollment::OutcomeInProgress
            || (int) $enrollment->current_proposal_version_id !== (int) $proposal->id
            || $proposal->state !== RegistrationProposalVersion::StateConfirmed) {
            throw ValidationException::withMessages(['placement' => 'Placement requires the current confirmed Registration Proposal.']);
        }

        $timetableQuery = PublishedTimetableVersion::query()
            ->where('term_id', $enrollment->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version');
        $currentTimetable = $this->lock($timetableQuery, $lockForUpdate)->first();

        if (! $currentTimetable instanceof PublishedTimetableVersion
            || (int) $currentTimetable->id !== (int) $proposal->published_timetable_version_id) {
            throw ValidationException::withMessages(['timetable' => 'The Published Timetable changed. Prepare and confirm a successor proposal.']);
        }

        $curriculum = $proposal->curriculumVersion;
        if (! $curriculum instanceof CurriculumVersion || $proposal->items->isEmpty()) {
            throw ValidationException::withMessages(['placement' => 'The proposal has no current curriculum or placement items.']);
        }

        $this->academicEligibility->assertEligible(
            $enrollment,
            $curriculum,
            $proposal->items->pluck('termOffering')->filter()->values(),
        );
        $this->unitLoad->assertProposalPermitted($enrollment, $proposal, $lockForUpdate);

        $meetingRanges = [];

        foreach ($proposal->items as $item) {
            $sectionQuery = Section::query()->with('termOffering')->whereKey($item->section_id);
            $section = $this->lock($sectionQuery, $lockForUpdate)->first();
            $reservationQuery = EnrollmentSeatReservation::query()
                ->where('registration_proposal_item_id', $item->id);
            $reservation = $this->lock($reservationQuery, $lockForUpdate)->first();

            if (! $section instanceof Section
                || $section->state !== Section::StateOpen
                || (int) $section->termOffering?->term_id !== (int) $enrollment->term_id
                || ! $reservation instanceof EnrollmentSeatReservation
                || $reservation->status !== EnrollmentSeatReservation::StatusActive
                || (int) $reservation->enrollment_id !== (int) $enrollment->id
                || (int) $reservation->section_id !== (int) $section->id
                || (int) $reservation->published_timetable_version_id !== (int) $currentTimetable->id
                || ($reservation->deadline !== null && $reservation->deadline->isPast())) {
                throw ValidationException::withMessages(['placement' => 'A current proposal item has a stale, expired, or invalid protected placement.']);
            }

            $otherCapacity = EnrollmentSeatReservation::query()
                ->where('section_id', $section->id)
                ->whereKeyNot($reservation->id)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses());
            $otherCapacityCount = $this->lock($otherCapacity, $lockForUpdate)->count();
            $officialCountQuery = CourseEnrollment::query()
                ->where('section_id', $section->id)
                ->where('is_current', true)
                ->where('status', CourseEnrollment::StatusActive);
            $officialCount = $this->lock($officialCountQuery, $lockForUpdate)->count();

            if (($otherCapacityCount + $officialCount) >= $section->capacity) {
                throw ValidationException::withMessages(['capacity' => "{$section->code} no longer has capacity for this reservation. Registrar must prepare a replacement."]);
            }

            $currentMeetings = PublishedTimetableMeeting::query()
                ->where('published_timetable_version_id', $currentTimetable->id)
                ->where('section_id', $section->id)
                ->orderBy('meeting_sequence')
                ->get();
            $snapshot = collect($item->meeting_snapshot)
                ->sortBy('meeting_sequence')
                ->values()
                ->map(fn (array $meeting): array => $this->meetingIdentity($meeting));
            $current = $currentMeetings
                ->map(fn (PublishedTimetableMeeting $meeting): array => $this->meetingIdentity($meeting->toArray()));
            $schedulingTreatment = $item->termOffering?->curriculumEntry?->courseSpecification?->scheduling_treatment;
            $scheduleMatches = $item->scheduling_treatment_snapshot === $schedulingTreatment
                && match ($schedulingTreatment) {
                    CourseSpecification::SchedulingRecurring => $current->isNotEmpty() && $snapshot->all() === $current->all(),
                    CourseSpecification::SchedulingExternallyArranged => $current->isEmpty() && $snapshot->isEmpty(),
                    default => false,
                };

            if (! $scheduleMatches) {
                throw ValidationException::withMessages(['timetable' => 'A protected Class Offering no longer matches the confirmed timetable snapshot.']);
            }

            foreach ($current as $meeting) {
                foreach ($meetingRanges as $other) {
                    if ($meeting['day_of_week'] === $other['day_of_week']
                        && $meeting['starts_at'] < $other['ends_at']
                        && $meeting['ends_at'] > $other['starts_at']) {
                        throw ValidationException::withMessages(['conflict' => 'The current protected placements contain an overlapping meeting.']);
                    }
                }

                $meetingRanges[] = $meeting;
            }
        }
    }

    public function passes(Enrollment $enrollment, RegistrationProposalVersion $proposal): bool
    {
        try {
            $this->assertCurrent($enrollment, $proposal);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** @return array{meeting_sequence:int,day_of_week:int,starts_at:string,ends_at:string,faculty_user_id:int,room_id:int|null,modality:string} */
    private function meetingIdentity(array $meeting): array
    {
        return [
            'meeting_sequence' => (int) $meeting['meeting_sequence'],
            'day_of_week' => (int) $meeting['day_of_week'],
            'starts_at' => (string) $meeting['starts_at'],
            'ends_at' => (string) $meeting['ends_at'],
            'faculty_user_id' => (int) $meeting['faculty_user_id'],
            'room_id' => isset($meeting['room_id']) ? (int) $meeting['room_id'] : null,
            'modality' => (string) $meeting['modality'],
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function lock(Builder $query, bool $lockForUpdate): Builder
    {
        return $lockForUpdate ? $query->lockForUpdate() : $query;
    }
}
