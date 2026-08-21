<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Models\CorVersion;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationLateAuthority;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCourseDrop
{
    public function __construct(private readonly CalendarPhaseGateService $calendar) {}

    public function execute(
        Enrollment $enrollment,
        CourseEnrollment $course,
        User $actor,
        string $reason,
        string $authorityReference,
        ?RegistrationLateAuthority $lateAuthority = null,
    ): CourseDropRecord {
        if (! $actor->canAuthenticate()
            || ! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('Only authorized Registrar staff may record a Course Drop.');
        }

        return DB::transaction(function () use ($enrollment, $course, $actor, $reason, $authorityReference, $lateAuthority): CourseDropRecord {
            $lockedEnrollment = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $lockedCourse = CourseEnrollment::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $currentCor = CorVersion::query()->whereKey($lockedEnrollment->current_cor_version_id)->lockForUpdate()->first();

            $late = $lateAuthority instanceof RegistrationLateAuthority
                ? RegistrationLateAuthority::query()->whereKey($lateAuthority->id)->lockForUpdate()->first()
                : null;
            if (! $late instanceof RegistrationLateAuthority) {
                $this->calendar->assertAddDropAdjustmentWindowOpen((int) $lockedEnrollment->term_id);
            }

            if ($lockedEnrollment->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                || (int) $lockedCourse->enrollment_id !== (int) $lockedEnrollment->id
                || ! $lockedCourse->is_current || ! $currentCor instanceof CorVersion
                || blank($reason) || blank($authorityReference)) {
                throw ValidationException::withMessages(['drop' => 'Course Drop requires a current official course, reason, authority, and COR source.']);
            }

            if ($late instanceof RegistrationLateAuthority
                && ((int) $late->enrollment_id !== (int) $lockedEnrollment->id
                    || (int) $late->term_id !== (int) $lockedEnrollment->term_id
                    || $late->action_type !== RegistrationLateAuthority::ActionCourseDrop
                    || (int) $late->before_course_enrollment_id !== (int) $lockedCourse->id
                    || $late->after_section_id !== null
                    || $late->authority_reference !== $authorityReference
                    || $late->consumed_at !== null)) {
                throw ValidationException::withMessages(['late_authority' => 'The late authority is stale, already used, or does not match this exact Course Drop.']);
            }

            if (CourseEnrollment::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('is_current', true)
                ->where('status', CourseEnrollment::StatusActive)
                ->lockForUpdate()
                ->count() <= 1) {
                throw ValidationException::withMessages(['drop' => 'Dropping the final active course is a full-withdrawal decision owned by Clinic 5, not Course Drop.']);
            }

            if ($lockedCourse->gradeRosterRow()->whereNotNull('released_at')->exists()) {
                throw ValidationException::withMessages(['drop' => 'A released academic result requires the controlled academic-record correction workflow before Course Drop.']);
            }

            $lockedCourse->update(['is_current' => false, 'status' => CourseEnrollment::StatusDropped, 'effective_until' => now(), 'dropped_at' => now(), 'status_reason' => $reason]);
            $lockedEnrollment->termAccount()->update(['state' => TermAccount::StateOpen]);
            $record = CourseDropRecord::query()->create([
                'enrollment_id' => $lockedEnrollment->id,
                'course_enrollment_id' => $lockedCourse->id,
                'authority_reference' => $authorityReference,
                'reason' => $reason,
                'finance_state' => 'AccountingReviewPending',
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            $late?->update(['consumed_at' => now()]);

            RegistrationCaseEvent::query()->create([
                'enrollment_id' => $lockedEnrollment->id,
                'sequence' => ((int) $lockedEnrollment->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => $late instanceof RegistrationLateAuthority ? 'LateAuthorizedCourseDrop' : 'CourseDrop',
                'from_outcome' => $lockedEnrollment->canonical_outcome,
                'to_outcome' => $lockedEnrollment->canonical_outcome,
                'reason' => $reason,
                'authority_reference' => $authorityReference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);

            $snapshot = $currentCor->snapshot;
            $snapshot['courses'] = collect($snapshot['courses'] ?? [])->reject(fn (array $row): bool => (int) $row['course_enrollment_id'] === (int) $lockedCourse->id)->values()->all();
            $snapshot['change'] = ['type' => 'CourseDrop', 'record_id' => $record->id, 'finance_state' => 'AccountingReviewPending'];
            $snapshot['issued_at'] = now()->toIso8601String();
            $successor = CorVersion::query()->create([
                'enrollment_id' => $lockedEnrollment->id,
                'supersedes_version_id' => $currentCor->id,
                'version' => $currentCor->version + 1,
                'registration_proposal_version_id' => $currentCor->registration_proposal_version_id,
                'assessment_id' => $currentCor->assessment_id,
                'published_timetable_version_id' => $currentCor->published_timetable_version_id,
                'snapshot' => $snapshot,
                'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);
            $lockedEnrollment->update(['current_cor_version_id' => $successor->id, 'lock_version' => $lockedEnrollment->lock_version + 1]);

            return $record;
        }, attempts: 3);
    }
}
