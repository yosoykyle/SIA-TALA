<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\Section;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordRegistrationAdjustmentFinanceConfirmation
{
    public function execute(
        Enrollment $enrollment,
        ?CourseEnrollment $currentCourse,
        Section $replacementSection,
        User $actor,
        string $authorityReference,
    ): RegistrationAdjustmentFinanceConfirmation {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only active Accounting staff may confirm a no-additional-cost adjustment.');
        }

        return DB::transaction(function () use ($enrollment, $currentCourse, $replacementSection, $actor, $authorityReference): RegistrationAdjustmentFinanceConfirmation {
            $lockedEnrollment = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $lockedCourse = $currentCourse instanceof CourseEnrollment
                ? CourseEnrollment::query()->whereKey($currentCourse->id)->lockForUpdate()->firstOrFail()
                : null;
            $lockedReplacement = Section::query()->whereKey($replacementSection->id)->lockForUpdate()->firstOrFail();

            if ($lockedEnrollment->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                || ($lockedCourse instanceof CourseEnrollment && ((int) $lockedCourse->enrollment_id !== (int) $lockedEnrollment->id
                    || ! $lockedCourse->is_current))
                || (int) $lockedReplacement->termOffering()->value('term_id') !== (int) $lockedEnrollment->term_id
                || blank($authorityReference)) {
                throw ValidationException::withMessages(['confirmation' => 'Accounting confirmation requires a current exact-Term registration change and recorded authority.']);
            }

            return RegistrationAdjustmentFinanceConfirmation::query()->firstOrCreate(
                [
                    'enrollment_id' => $lockedEnrollment->id,
                    'current_course_enrollment_id' => $lockedCourse?->id,
                    'replacement_section_id' => $lockedReplacement->id,
                    'financial_effect' => RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
                    'authority_reference' => $authorityReference,
                ],
                ['confirmed_by' => $actor->id, 'confirmed_at' => now()],
            );
        }, attempts: 3);
    }
}
