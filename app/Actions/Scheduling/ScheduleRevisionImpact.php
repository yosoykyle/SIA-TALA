<?php

namespace App\Actions\Scheduling;

final readonly class ScheduleRevisionImpact
{
    /**
     * @param  list<array<string, mixed>>  $meetingChanges
     * @param  list<array<string, mixed>>  $findings
     */
    public function __construct(
        private string $changeType,
        private array $meetingChanges,
        private int $affectedStudents,
        private int $affectedFaculty,
        private array $findings,
        private int $activeOfficialRegistrations = 0,
        private int $capacityHoldingReservations = 0,
    ) {}

    public function changeType(): string
    {
        return $this->changeType;
    }

    /** @return list<array<string, mixed>> */
    public function meetingChanges(): array
    {
        return $this->meetingChanges;
    }

    public function affectedStudents(): int
    {
        return $this->affectedStudents;
    }

    public function affectedFaculty(): int
    {
        return $this->affectedFaculty;
    }

    /** @return list<array<string, mixed>> */
    public function findings(): array
    {
        return $this->findings;
    }

    public function activeOfficialRegistrations(): int
    {
        return $this->activeOfficialRegistrations;
    }

    public function capacityHoldingReservations(): int
    {
        return $this->capacityHoldingReservations;
    }

    public function passes(): bool
    {
        return $this->activeOfficialRegistrations === 0
            && $this->capacityHoldingReservations === 0
            && collect($this->findings)->where('severity', 'blocking')->isEmpty();
    }

    public function blockingMessage(): string
    {
        if ($this->activeOfficialRegistrations > 0) {
            return 'Section cancellation is blocked by active official course registrations.';
        }

        if ($this->capacityHoldingReservations > 0) {
            return 'Section cancellation is blocked by capacity-holding seat reservations.';
        }

        $finding = collect($this->findings)->firstWhere('severity', 'blocking');

        return is_array($finding)
            ? (string) ($finding['message'] ?? 'The proposed live revision failed hard-constraint validation.')
            : 'The proposed live revision failed hard-constraint validation.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'change_type' => $this->changeType,
            'changed_meetings' => count($this->meetingChanges),
            'affected_students' => $this->affectedStudents,
            'affected_faculty' => $this->affectedFaculty,
            'active_official_registrations' => $this->activeOfficialRegistrations,
            'capacity_holding_reservations' => $this->capacityHoldingReservations,
            'passes' => $this->passes(),
            'findings' => $this->findings,
        ];
    }
}
