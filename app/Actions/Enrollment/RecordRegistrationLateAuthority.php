<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationLateAuthority;
use App\Models\Section;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordRegistrationLateAuthority
{
    public function execute(
        Enrollment $enrollment,
        ?CourseEnrollment $beforeCourse,
        ?Section $afterSection,
        User $actor,
        string $actionType,
        string $approvingOffice,
        string $authorityReference,
        CarbonImmutable $authorityDate,
        ?string $reason,
        ?CarbonImmutable $effectiveAt,
        string $learnerAcknowledgementBasis,
        ?string $sourceAcademicDecision,
    ): RegistrationLateAuthority {
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may record late registration authority.');
        }

        return DB::transaction(function () use ($enrollment, $beforeCourse, $afterSection, $actor, $actionType, $approvingOffice, $authorityReference, $authorityDate, $reason, $effectiveAt, $learnerAcknowledgementBasis, $sourceAcademicDecision): RegistrationLateAuthority {
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $course = $beforeCourse instanceof CourseEnrollment
                ? CourseEnrollment::query()->whereKey($beforeCourse->id)->lockForUpdate()->firstOrFail()
                : null;
            $section = $afterSection instanceof Section
                ? Section::query()->whereKey($afterSection->id)->lockForUpdate()->firstOrFail()
                : null;

            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                || $locked->current_cor_version_id === null
                || ! in_array($actionType, [RegistrationLateAuthority::ActionAdjustment, RegistrationLateAuthority::ActionCourseDrop], true)
                || ($course instanceof CourseEnrollment && ((int) $course->enrollment_id !== (int) $locked->id
                    || ! $course->is_current
                    || $course->status !== CourseEnrollment::StatusActive))
                || ($actionType === RegistrationLateAuthority::ActionCourseDrop && ! $course instanceof CourseEnrollment)
                || ($actionType === RegistrationLateAuthority::ActionAdjustment && ! $section instanceof Section)
                || ($section instanceof Section && (int) $section->termOffering()->value('term_id') !== (int) $locked->term_id)
                || collect([$approvingOffice, $authorityReference, $learnerAcknowledgementBasis])->contains(fn (string $value): bool => blank($value))) {
                throw ValidationException::withMessages(['late_authority' => 'Identify the current official registration, exact subject change, approving office, approval reference/date and student acknowledgement.']);
            }

            $authority = RegistrationLateAuthority::query()->firstOrCreate(
                [
                    'enrollment_id' => $locked->id,
                    'action_type' => $actionType,
                    'authority_reference' => trim($authorityReference),
                ],
                [
                    'term_id' => $locked->term_id,
                    'before_course_enrollment_id' => $course?->id,
                    'after_section_id' => $section?->id,
                    'approving_office' => trim($approvingOffice),
                    'authority_date' => $authorityDate,
                    'reason' => filled($reason) ? trim($reason) : null,
                    'effective_at' => $effectiveAt,
                    'learner_acknowledgement_basis' => trim($learnerAcknowledgementBasis),
                    'source_academic_decision' => filled($sourceAcademicDecision) ? trim($sourceAcademicDecision) : null,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ],
            );

            if (($authority->before_course_enrollment_id === null ? null : (int) $authority->before_course_enrollment_id) !== $course?->id
                || ($authority->after_section_id === null ? null : (int) $authority->after_section_id) !== $section?->id
                || (int) $authority->term_id !== (int) $locked->term_id
                || $authority->approving_office !== trim($approvingOffice)
                || $authority->authority_date->toDateString() !== $authorityDate->toDateString()
                || $authority->consumed_at !== null) {
                throw ValidationException::withMessages(['late_authority' => 'This approval reference is already recorded for different facts or has been used. Review its existing record.']);
            }

            return $authority;
        }, attempts: 3);
    }
}
