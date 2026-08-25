<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaceRegistrationProposal
{
    public function __construct(
        private readonly CalendarPhaseGateService $calendar,
        private readonly RegistrationPlacementValidator $validator,
        private readonly StudentUnitLoadService $unitLoad,
    ) {}

    public function execute(RegistrationProposalVersion $proposal, User $actor, ?\DateTimeInterface $expectedDeadline = null): RegistrationProposalVersion
    {
        if (! $actor->canAuthenticate()
            || ! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('Only authorized Registrar staff may place a Registration Proposal.');
        }

        return DB::transaction(function () use ($proposal, $actor, $expectedDeadline): RegistrationProposalVersion {
            $locked = RegistrationProposalVersion::query()->with(['enrollment', 'items'])->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $enrollment = $locked->enrollment()->lockForUpdate()->firstOrFail();

            if ($locked->state !== RegistrationProposalVersion::StateConfirmed
                || $enrollment->canonical_outcome !== Enrollment::OutcomeInProgress
                || (int) $enrollment->current_proposal_version_id !== (int) $locked->id) {
                throw ValidationException::withMessages(['proposal' => 'Placement requires the current confirmed proposal.']);
            }

            $this->unitLoad->assertProposalPermitted($enrollment, $locked, lockForUpdate: true);

            $deadline = $this->calendar->enrollmentDeadline((int) $enrollment->term_id);
            if ($expectedDeadline !== null
                && $expectedDeadline->getTimestamp() !== $deadline->getTimestamp()) {
                throw ValidationException::withMessages(['deadline' => 'Use the current institutional enrollment deadline; per-case reservation deadlines are not permitted.']);
            }

            $currentTimetable = PublishedTimetableVersion::query()
                ->where('term_id', $enrollment->term_id)
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if (! $currentTimetable instanceof PublishedTimetableVersion
                || (int) $currentTimetable->id !== (int) $locked->published_timetable_version_id) {
                throw ValidationException::withMessages(['timetable' => 'The Published Timetable changed. Prepare a successor proposal.']);
            }

            $meetingRanges = [];

            foreach ($locked->items as $item) {
                $section = Section::query()->whereKey($item->section_id)->lockForUpdate()->firstOrFail();
                $existing = EnrollmentSeatReservation::query()
                    ->where('registration_proposal_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();
                $capacityUsed = EnrollmentSeatReservation::query()
                    ->where('section_id', $section->id)
                    ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                    ->where(function ($query) use ($item): void {
                        $query->whereNull('registration_proposal_item_id')
                            ->orWhere('registration_proposal_item_id', '!=', $item->id);
                    })
                    ->lockForUpdate()
                    ->count()
                    + CourseEnrollment::query()->where('section_id', $section->id)->where('is_current', true)->lockForUpdate()->count();

                if ($section->state !== Section::StateOpen || $capacityUsed >= $section->capacity) {
                    throw ValidationException::withMessages(['capacity' => "{$section->code} has no remaining capacity. Registrar must choose a replacement."]);
                }

                foreach ($item->meeting_snapshot as $meeting) {
                    foreach ($meetingRanges as $other) {
                        if ((int) $meeting['day_of_week'] === (int) $other['day_of_week']
                            && (string) $meeting['starts_at'] < (string) $other['ends_at']
                            && (string) $meeting['ends_at'] > (string) $other['starts_at']) {
                            throw ValidationException::withMessages(['conflict' => 'The proposal contains overlapping published meetings.']);
                        }
                    }
                    $meetingRanges[] = $meeting;
                }

                if ($existing instanceof EnrollmentSeatReservation) {
                    if ((int) $existing->section_id !== (int) $section->id
                        || (int) $existing->published_timetable_version_id !== (int) $currentTimetable->id) {
                        throw ValidationException::withMessages(['placement' => 'The existing reservation source does not match this proposal. Prepare a successor proposal.']);
                    }
                    if ($existing->status !== EnrollmentSeatReservation::StatusActive) {
                        $existing->update([
                            'status' => EnrollmentSeatReservation::StatusActive,
                            'reserved_at' => now(),
                            'released_at' => null,
                            'deadline' => $deadline,
                            'registrar_user_id' => $actor->id,
                            'lock_version' => $existing->lock_version + 1,
                        ]);
                    }
                } else {
                    EnrollmentSeatReservation::query()->create([
                        'registration_proposal_item_id' => $item->id,
                        'enrollment_id' => $locked->enrollment_id,
                        'course_enrollment_id' => null,
                        'section_id' => $section->id,
                        'published_timetable_version_id' => $currentTimetable->id,
                        'status' => EnrollmentSeatReservation::StatusActive,
                        'reserved_at' => now(),
                        'deadline' => $deadline,
                        'registrar_user_id' => $actor->id,
                        'lock_version' => 1,
                    ]);
                }
            }

            $placed = $locked->refresh()->load('items.reservation');
            $this->validator->assertCurrent($enrollment->fresh(), $placed, lockForUpdate: true);

            return $placed;
        }, attempts: 3);
    }
}
