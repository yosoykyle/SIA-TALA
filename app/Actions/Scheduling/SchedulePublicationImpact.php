<?php

namespace App\Actions\Scheduling;

final readonly class SchedulePublicationImpact
{
    public function __construct(
        private int $newAssignments,
        private int $changedAssignments,
        private int $removedAssignments,
        private int $unchangedAssignments,
        private int $affectedFaculty,
        private int $activeOfficialRegistrations,
        private int $affectedStudents,
        private ?int $currentPublicationVersion,
    ) {}

    public function newAssignments(): int
    {
        return $this->newAssignments;
    }

    public function changedAssignments(): int
    {
        return $this->changedAssignments;
    }

    public function removedAssignments(): int
    {
        return $this->removedAssignments;
    }

    public function unchangedAssignments(): int
    {
        return $this->unchangedAssignments;
    }

    public function affectedFaculty(): int
    {
        return $this->affectedFaculty;
    }

    public function activeOfficialRegistrations(): int
    {
        return $this->activeOfficialRegistrations;
    }

    public function affectedStudents(): int
    {
        return $this->affectedStudents;
    }

    public function currentPublicationVersion(): ?int
    {
        return $this->currentPublicationVersion;
    }

    public function blocksFullReplacement(): bool
    {
        return $this->activeOfficialRegistrations > 0;
    }

    /**
     * @return array{
     *     new_assignments: int,
     *     changed_assignments: int,
     *     removed_assignments: int,
     *     unchanged_assignments: int,
     *     affected_faculty: int,
     *     active_official_registrations: int,
     *     affected_students: int,
     *     current_publication_version: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'new_assignments' => $this->newAssignments,
            'changed_assignments' => $this->changedAssignments,
            'removed_assignments' => $this->removedAssignments,
            'unchanged_assignments' => $this->unchangedAssignments,
            'affected_faculty' => $this->affectedFaculty,
            'active_official_registrations' => $this->activeOfficialRegistrations,
            'affected_students' => $this->affectedStudents,
            'current_publication_version' => $this->currentPublicationVersion,
        ];
    }
}
