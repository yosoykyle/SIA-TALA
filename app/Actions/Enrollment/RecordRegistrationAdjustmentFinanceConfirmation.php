<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalVersion;
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
        ?string $authorityReference = null,
        ?int $proposalVersionId = null,
    ): RegistrationAdjustmentFinanceConfirmation {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only active Accounting staff may confirm a no-additional-cost adjustment.');
        }

        return DB::transaction(function () use ($enrollment, $currentCourse, $replacementSection, $actor, $authorityReference, $proposalVersionId): RegistrationAdjustmentFinanceConfirmation {
            $lockedEnrollment = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $sourceProposalVersionId = $proposalVersionId ?? $lockedEnrollment->current_proposal_version_id;
            $proposal = $sourceProposalVersionId === null
                ? null
                : RegistrationProposalVersion::query()->whereKey($sourceProposalVersionId)->lockForUpdate()->first();
            $proposalConfirmation = $proposal === null
                ? null
                : RegistrationProposalConfirmation::query()->where('registration_proposal_version_id', $proposal->id)->lockForUpdate()->first();
            $lockedCourse = $currentCourse instanceof CourseEnrollment
                ? CourseEnrollment::query()->whereKey($currentCourse->id)->lockForUpdate()->firstOrFail()
                : null;
            $lockedReplacement = Section::query()->whereKey($replacementSection->id)->lockForUpdate()->firstOrFail();

            $proposalMatchesChange = false;
            if (! filled($authorityReference) && $proposal instanceof RegistrationProposalVersion) {
                $currentSectionIds = CourseEnrollment::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->where('is_current', true)
                    ->where('status', CourseEnrollment::StatusActive)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('section_id')
                    ->map(fn (int $sectionId): int => $sectionId)
                    ->all();
                $proposalSectionIds = $proposal->items()->orderBy('sequence')->pluck('section_id')->map(fn (int $sectionId): int => $sectionId)->all();
                $removedSectionIds = array_values(array_diff($currentSectionIds, $proposalSectionIds));
                $addedSectionIds = array_values(array_diff($proposalSectionIds, $currentSectionIds));
                if ($lockedCourse instanceof CourseEnrollment) {
                    $proposalMatchesChange = (int) $lockedCourse->enrollment_id === (int) $lockedEnrollment->id
                        && $lockedCourse->is_current
                        && in_array((int) $lockedCourse->section_id, $currentSectionIds, true)
                        && count($removedSectionIds) === 1
                        && $removedSectionIds[0] === (int) $lockedCourse->section_id
                        && count($addedSectionIds) === 1
                        && $addedSectionIds[0] === (int) $lockedReplacement->id;
                } else {
                    $proposalMatchesChange = $removedSectionIds === []
                        && count($addedSectionIds) === 1
                        && $addedSectionIds[0] === (int) $lockedReplacement->id;
                }
            }

            if ($lockedEnrollment->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                || (! filled($authorityReference) && ($proposal === null
                    || (int) $proposal->enrollment_id !== (int) $lockedEnrollment->id
                    || (int) $lockedEnrollment->current_proposal_version_id !== (int) $proposal->id
                    || $proposal->purpose !== RegistrationProposalVersion::PurposeAdjustment
                    || $proposal->state !== RegistrationProposalVersion::StateConfirmed
                    || $proposalConfirmation === null
                    || (int) $proposalConfirmation->learner_user_id !== (int) $lockedEnrollment->credential_user_id
                    || $proposalConfirmation->confirmed_at === null
                    || ! $proposalMatchesChange))
                || ($lockedCourse instanceof CourseEnrollment && ((int) $lockedCourse->enrollment_id !== (int) $lockedEnrollment->id
                    || ! $lockedCourse->is_current))
                || (int) $lockedReplacement->termOffering()->value('term_id') !== (int) $lockedEnrollment->term_id) {
                throw ValidationException::withMessages(['confirmation' => 'Accounting confirmation requires a current exact-Term registration change.']);
            }

            $reference = filled($authorityReference)
                ? trim((string) $authorityReference)
                : 'accounting-no-cost:'.$lockedEnrollment->id.':proposal:'.$proposal->id.':'.($lockedCourse?->id ?? 'new').':'.$lockedReplacement->id;

            return RegistrationAdjustmentFinanceConfirmation::query()->firstOrCreate(
                [
                    'enrollment_id' => $lockedEnrollment->id,
                    'current_course_enrollment_id' => $lockedCourse?->id,
                    'replacement_section_id' => $lockedReplacement->id,
                    'financial_effect' => RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
                    'authority_reference' => $reference,
                ],
                ['confirmed_by' => $actor->id, 'confirmed_at' => now()],
            );
        }, attempts: 3);
    }
}
