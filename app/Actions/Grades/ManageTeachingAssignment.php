<?php

namespace App\Actions\Grades;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\GradeRosterVersion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ManageTeachingAssignment
{
    public function designate(Section $section, User $faculty, User $actor, string $authorityReference): ClassOfferingTeachingAssignment
    {
        return $this->record($section, $faculty, $actor, $authorityReference, ClassOfferingTeachingAssignment::RoleDesignated);
    }

    public function addCoFaculty(Section $section, User $faculty, User $actor, string $authorityReference): ClassOfferingTeachingAssignment
    {
        return $this->record($section, $faculty, $actor, $authorityReference, ClassOfferingTeachingAssignment::RoleCoFaculty);
    }

    private function record(
        Section $section,
        User $faculty,
        User $actor,
        string $authorityReference,
        string $role,
    ): ClassOfferingTeachingAssignment {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can manage teaching assignments.');
        }

        if (! $faculty->hasRole(User::StaffRoleFaculty)) {
            throw new RuntimeException('A teaching assignment requires an active Faculty account.');
        }

        $authorityReference = trim($authorityReference);

        if ($authorityReference === '') {
            throw new RuntimeException('Teaching-assignment authority is required.');
        }

        return DB::transaction(function () use ($section, $faculty, $actor, $authorityReference, $role): ClassOfferingTeachingAssignment {
            $section = Section::query()->lockForUpdate()->findOrFail($section->id);
            $current = ClassOfferingTeachingAssignment::query()
                ->where('section_id', $section->id)
                ->where('role', $role)
                ->where('state', ClassOfferingTeachingAssignment::StateActive)
                ->when(
                    $role === ClassOfferingTeachingAssignment::RoleCoFaculty,
                    fn ($query) => $query->where('faculty_user_id', $faculty->id),
                )
                ->lockForUpdate()
                ->get();

            if ($current->count() === 1 && (int) $current->first()->faculty_user_id === (int) $faculty->id) {
                return $current->first();
            }

            $replacement = ClassOfferingTeachingAssignment::query()->create([
                'term_offering_id' => $section->term_offering_id,
                'section_id' => $section->id,
                'faculty_user_id' => $faculty->id,
                'role' => $role,
                'state' => ClassOfferingTeachingAssignment::StateActive,
                'authority_reference' => $authorityReference,
                'assigned_by' => $actor->id,
                'effective_at' => now(),
            ]);

            foreach ($current as $assignment) {
                $assignment->update([
                    'state' => ClassOfferingTeachingAssignment::StateReplaced,
                    'ended_at' => now(),
                    'replaced_by_assignment_id' => $replacement->id,
                ]);
            }

            if ($role === ClassOfferingTeachingAssignment::RoleDesignated) {
                $rosters = GradeRoster::query()->where('section_id', $section->id)->lockForUpdate()->get();

                foreach ($rosters as $roster) {
                    $roster->versions()->where('state', GradeRosterVersion::StateSubmitted)->update([
                        'state' => GradeRosterVersion::StateInvalidated,
                        'invalidated_at' => now(),
                        'invalidation_reason' => 'Designated Faculty changed after submission.',
                    ]);
                    $roster->update([
                        'faculty_user_id' => $faculty->id,
                        'teaching_assignment_id' => $replacement->id,
                        'state' => $roster->state === GradeRoster::StateReleased ? $roster->state : GradeRoster::StateDraft,
                        'invalidated_at' => $roster->state === GradeRoster::StateReleased ? null : now(),
                        'invalidated_by' => $roster->state === GradeRoster::StateReleased ? null : $actor->id,
                        'invalidation_reason' => $roster->state === GradeRoster::StateReleased ? null : 'Designated Faculty changed; review and resubmit.',
                        'lock_version' => $roster->lock_version + 1,
                    ]);
                }
            }

            return $replacement;
        }, attempts: 3);
    }
}
