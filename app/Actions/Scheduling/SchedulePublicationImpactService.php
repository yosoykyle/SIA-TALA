<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\CourseEnrollment;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class SchedulePublicationImpactService
{
    public function preview(ScheduleGenerationRun $run): SchedulePublicationImpact
    {
        $currentPublishedRun = $this->currentPublishedRun($run);
        $candidateRows = $this->candidateRows($run);
        $currentMeetings = $this->currentMeetings($currentPublishedRun);
        $activeRegistrations = $this->activeOfficialRegistrations($currentMeetings);

        return $this->calculate($currentPublishedRun, $candidateRows, $currentMeetings, $activeRegistrations);
    }

    /**
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     */
    public function lockForPublication(
        ?ScheduleGenerationRun $currentPublishedRun,
        Collection $candidateRows,
    ): SchedulePublicationImpact {
        $currentMeetings = $this->currentMeetings($currentPublishedRun, lock: true);
        $activeRegistrations = $this->activeOfficialRegistrations($currentMeetings, lock: true);

        return $this->calculate($currentPublishedRun, $candidateRows, $currentMeetings, $activeRegistrations);
    }

    public function modalityFor(CandidateScheduleRow $candidateRow): string
    {
        $demand = $candidateRow->getRelation('schedulingDemand');

        if (! $demand instanceof SchedulingDemand) {
            throw ValidationException::withMessages([
                'candidate_schedule_rows' => 'A candidate assignment must reference a current Scheduling Demand.',
            ]);
        }

        $group = $demand->getRelation('sectionDeliveryGroup');
        $offering = $demand->getRelation('termOffering');

        if ($group instanceof SectionDeliveryGroup && filled($group->modality)) {
            return (string) $group->modality;
        }

        if ($offering instanceof TermOffering) {
            return (string) $offering->modality;
        }

        throw ValidationException::withMessages([
            'candidate_schedule_rows' => 'A candidate assignment must reference a current Term Offering.',
        ]);
    }

    private function currentPublishedRun(ScheduleGenerationRun $run): ?ScheduleGenerationRun
    {
        return ScheduleGenerationRun::query()
            ->where('term_id', $run->term_id)
            ->where('status', ScheduleGenerationRun::StatusPublished)
            ->whereKeyNot($run->getKey())
            ->orderByDesc('publication_version')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, CandidateScheduleRow> */
    private function candidateRows(ScheduleGenerationRun $run): Collection
    {
        return CandidateScheduleRow::query()
            ->where('schedule_run_id', $run->id)
            ->with([
                'schedulingDemand.sectionDeliveryGroup',
                'schedulingDemand.termOffering',
            ])
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, SectionMeeting> */
    private function currentMeetings(
        ?ScheduleGenerationRun $currentPublishedRun,
        bool $lock = false,
    ): Collection {
        if (! $currentPublishedRun instanceof ScheduleGenerationRun) {
            return new Collection;
        }

        $query = SectionMeeting::query()
            ->where('schedule_run_id', $currentPublishedRun->id)
            ->where('state', SectionMeeting::StateActive)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, SectionMeeting>  $currentMeetings
     * @return Collection<int, CourseEnrollment>
     */
    private function activeOfficialRegistrations(Collection $currentMeetings, bool $lock = false): Collection
    {
        if ($currentMeetings->isEmpty()) {
            return new Collection;
        }

        $sectionIds = $currentMeetings
            ->loadMissing('schedulingDemand.sectionDeliveryGroup')
            ->map(fn (SectionMeeting $meeting): int => (int) $meeting->schedulingDemand?->sectionDeliveryGroup?->section_id)
            ->filter()
            ->unique();

        $query = CourseEnrollment::query()
            ->whereIn('section_id', $sectionIds)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->with('enrollment')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     * @param  Collection<int, SectionMeeting>  $currentMeetings
     * @param  Collection<int, CourseEnrollment>  $activeRegistrations
     */
    private function calculate(
        ?ScheduleGenerationRun $currentPublishedRun,
        Collection $candidateRows,
        Collection $currentMeetings,
        Collection $activeRegistrations,
    ): SchedulePublicationImpact {
        $candidateByKey = $candidateRows->keyBy(
            fn (CandidateScheduleRow $row): string => $this->assignmentKey($row->scheduling_demand_id, $row->meeting_sequence),
        );
        $currentByKey = $currentMeetings->keyBy(
            fn (SectionMeeting $meeting): string => $this->assignmentKey($meeting->scheduling_demand_id, $meeting->meeting_sequence),
        );

        $newAssignments = 0;
        $changedAssignments = 0;
        $unchangedAssignments = 0;
        $affectedFacultyIds = [];

        foreach ($candidateByKey as $key => $candidateRow) {
            $currentMeeting = $currentByKey->get($key);

            if (! $currentMeeting instanceof SectionMeeting) {
                $newAssignments++;
                $affectedFacultyIds[(int) $candidateRow->faculty_user_id] = true;

                continue;
            }

            if ($this->sameAssignment($candidateRow, $currentMeeting)) {
                $unchangedAssignments++;

                continue;
            }

            $changedAssignments++;
            $affectedFacultyIds[(int) $candidateRow->faculty_user_id] = true;
            $affectedFacultyIds[(int) $currentMeeting->faculty_user_id] = true;
        }

        $removedMeetings = $currentByKey->diffKeys($candidateByKey);

        foreach ($removedMeetings as $removedMeeting) {
            $affectedFacultyIds[(int) $removedMeeting->faculty_user_id] = true;
        }

        $affectedStudentIds = $activeRegistrations
            ->map(fn (CourseEnrollment $registration): ?int => $registration->enrollment?->credential_user_id)
            ->filter(fn (?int $userId): bool => $userId !== null)
            ->unique()
            ->values();

        return new SchedulePublicationImpact(
            newAssignments: $newAssignments,
            changedAssignments: $changedAssignments,
            removedAssignments: $removedMeetings->count(),
            unchangedAssignments: $unchangedAssignments,
            affectedFaculty: count($affectedFacultyIds),
            activeOfficialRegistrations: $activeRegistrations->count(),
            affectedStudents: $affectedStudentIds->count(),
            currentPublicationVersion: $currentPublishedRun?->publication_version,
        );
    }

    private function sameAssignment(CandidateScheduleRow $candidateRow, SectionMeeting $currentMeeting): bool
    {
        return (int) $candidateRow->faculty_user_id === (int) $currentMeeting->faculty_user_id
            && ($candidateRow->room_id === null ? null : (int) $candidateRow->room_id) === ($currentMeeting->room_id === null ? null : (int) $currentMeeting->room_id)
            && (int) $candidateRow->day_of_week === (int) $currentMeeting->day_of_week
            && (string) $candidateRow->starts_at === (string) $currentMeeting->starts_at
            && (string) $candidateRow->ends_at === (string) $currentMeeting->ends_at
            && $this->modalityFor($candidateRow) === (string) $currentMeeting->modality;
    }

    private function assignmentKey(int|string $demandId, int|string $meetingSequence): string
    {
        return sprintf('%d:%d', $demandId, $meetingSequence);
    }
}
