<?php

namespace App\Actions\Enrollment;

use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableMeeting;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use Illuminate\Support\Collection;

class RegistrationShortageProjection
{
    /**
     * @return list<array{course:string,section:string,capacity:int,protected:int,available:int,alternatives:list<string>,owner:string,deadline:string|null,recovery:string,unmet_demand:int}>
     */
    public function for(Enrollment $enrollment): array
    {
        $enrollment->loadMissing([
            'currentProposalVersion.items.section.termOffering.curriculumEntry.courseSpecification.course',
            'currentProposalVersion.items.reservation',
        ]);
        $proposal = $enrollment->currentProposalVersion;

        if (! $proposal instanceof RegistrationProposalVersion
            || $proposal->state !== RegistrationProposalVersion::StateConfirmed) {
            return [];
        }

        $window = CalendarEvent::query()
            ->academicCalendarWindows()
            ->where('term_id', $enrollment->term_id)
            ->where('process_key', CalendarEvent::ProcessEnrollment)
            ->latest('end_at')
            ->first();
        $deadline = $window?->end_at;

        return $proposal->items
            ->filter(function ($item): bool {
                if ($item->reservation?->status === EnrollmentSeatReservation::StatusActive
                    && ($item->reservation->deadline === null || $item->reservation->deadline->isFuture())) {
                    return false;
                }

                return $item->section instanceof Section && $this->remainingCapacity($item->section) < 1;
            })
            ->map(function ($item) use ($proposal, $deadline): array {
                $section = $item->section;
                $protected = $section->capacity - $this->remainingCapacity($section);

                return [
                    'course' => "{$item->course_code_snapshot} — {$item->course_title_snapshot}",
                    'section' => $section->code,
                    'capacity' => (int) $section->capacity,
                    'protected' => $protected,
                    'available' => 0,
                    'alternatives' => $this->alternatives($section, (int) $proposal->published_timetable_version_id),
                    'owner' => 'Registrar',
                    'deadline' => $deadline?->toIso8601String(),
                    'recovery' => 'Registrar prepares a successor proposal with an available exact-Term Class Offering; no waitlist or silent move is created.',
                    'unmet_demand' => 1,
                ];
            })
            ->values()
            ->all();
    }

    /** @return Collection<int, int> */
    public function caseIds(): Collection
    {
        return Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->whereNotNull('current_proposal_version_id')
            ->with([
                'currentProposalVersion.items.section.termOffering.curriculumEntry.courseSpecification.course',
                'currentProposalVersion.items.reservation',
            ])
            ->get()
            ->filter(fn (Enrollment $enrollment): bool => $this->for($enrollment) !== [])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
    }

    private function remainingCapacity(Section $section): int
    {
        $held = EnrollmentSeatReservation::query()
            ->where('section_id', $section->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count();
        $official = CourseEnrollment::query()
            ->where('section_id', $section->id)
            ->where('is_current', true)
            ->where('status', CourseEnrollment::StatusActive)
            ->count();

        return max(0, (int) $section->capacity - $held - $official);
    }

    /** @return list<string> */
    private function alternatives(Section $section, int $timetableVersionId): array
    {
        $sectionIds = PublishedTimetableMeeting::query()
            ->where('published_timetable_version_id', $timetableVersionId)
            ->distinct()
            ->pluck('section_id');

        return Section::query()
            ->whereIn('id', $sectionIds)
            ->where('term_offering_id', $section->term_offering_id)
            ->whereKeyNot($section->id)
            ->where('state', Section::StateOpen)
            ->orderBy('code')
            ->get()
            ->filter(fn (Section $alternative): bool => $this->remainingCapacity($alternative) > 0)
            ->map(fn (Section $alternative): string => "{$alternative->code} ({$this->remainingCapacity($alternative)} available)")
            ->values()
            ->all();
    }
}
