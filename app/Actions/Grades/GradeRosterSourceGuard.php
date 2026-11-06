<?php

namespace App\Actions\Grades;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\User;
use RuntimeException;

class GradeRosterSourceGuard
{
    public function __construct(private readonly CurrentClassRosterOutput $membership) {}

    public function assertExpectedVersion(GradeRoster $roster, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $roster->current_version_number !== $expectedVersion) {
            throw new RuntimeException('The roster version changed. Reload and review the current submission before continuing.');
        }
    }

    public function assertCurrent(GradeRoster $roster, User $actor, bool $submitting = false): void
    {
        $assignment = ClassOfferingTeachingAssignment::query()->lockForUpdate()->find($roster->teaching_assignment_id);

        if (! $assignment instanceof ClassOfferingTeachingAssignment
            || $assignment->role !== ClassOfferingTeachingAssignment::RoleDesignated
            || $assignment->state !== ClassOfferingTeachingAssignment::StateActive
            || (int) $assignment->section_id !== (int) $roster->section_id
            || (int) $assignment->term_offering_id !== (int) $roster->term_offering_id
            || (int) $assignment->faculty_user_id !== (int) $roster->faculty_user_id
            || ($submitting && (int) $assignment->faculty_user_id !== (int) $actor->id)) {
            throw new RuntimeException('The designated Faculty assignment changed. Refresh the roster before continuing.');
        }

        $snapshot = $this->membership->membershipSnapshot($roster, $actor);
        if (! $snapshot['isCurrent']) {
            throw new RuntimeException('Official class membership changed. Refresh and resubmit the complete roster before continuing.');
        }
    }
}
