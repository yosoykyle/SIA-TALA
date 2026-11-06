<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\TermCohort;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordStudentSection
{
    public function execute(Enrollment $enrollment, User $actor, ?int $studentSectionId, int $expectedLockVersion): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $actor, $studentSectionId, $expectedLockVersion): Enrollment {
            $actor = User::query()->lockForUpdate()->find($actor->id);
            if (! $actor instanceof User || $actor->status !== User::StatusActive || ! $actor->hasRole(User::StaffRoleRegistrar)) {
                throw new AuthorizationException('Only active Registrar staff may record student-section membership.');
            }

            $locked = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                || $locked->student_profile_id === null
                || $locked->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages(['enrollment_id' => 'Reload the student record and choose a current official enrollment.']);
            }

            $section = $studentSectionId === null ? null : TermCohort::query()->lockForUpdate()->find($studentSectionId);
            $programId = $locked->currentProposalVersion?->curriculumVersion?->program_id;
            if ($studentSectionId !== null && (! $section instanceof TermCohort
                || $section->state !== 'Confirmed'
                || (int) $section->term_id !== (int) $locked->term_id
                || $programId === null
                || (int) $section->program_id !== (int) $programId)) {
                throw ValidationException::withMessages(['student_section_id' => 'Choose a confirmed student section for this enrollment’s term and program.']);
            }

            if ($locked->student_section_id === $studentSectionId) {
                return $locked;
            }

            $before = $locked->studentSection;
            $locked->update(['student_section_id' => $studentSectionId, 'lock_version' => $locked->lock_version + 1]);
            activity()->performedOn($locked)->causedBy($actor)->event('student_section_changed')
                ->withProperties([
                    'term_id' => $locked->term_id,
                    'before' => ['id' => $before?->id, 'reference' => $before?->reference],
                    'after' => ['id' => $section?->id, 'reference' => $section?->reference],
                ])->log('Student section updated');

            return $locked->refresh();
        }, attempts: 3);
    }
}
