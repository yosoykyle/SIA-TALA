<?php

namespace App\Actions\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\ExamAccessAccommodation;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Notifications\GeneralSystemNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExamAccessAccommodationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, User $actor, ?CarbonImmutable $submittedAt = null): ExamAccessAccommodation
    {
        $studentProfile = StudentProfile::query()->with('user')->findOrFail((int) ($data['student_profile_id'] ?? 0));
        $this->authorizeSubmission($studentProfile, $actor);
        $validated = $this->validateSubmission($data, $studentProfile);
        $timestamp = $submittedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($validated, $actor, $timestamp): ExamAccessAccommodation {
            StudentProfile::query()->lockForUpdate()->findOrFail($validated['student_profile_id']);

            $openQuery = ExamAccessAccommodation::query()
                ->where('student_profile_id', $validated['student_profile_id'])
                ->whereIn('status', [
                    ExamAccessAccommodation::StatusPending,
                    ExamAccessAccommodation::StatusApproved,
                ])
                ->where('scope', $validated['scope']);

            if ($validated['scope'] === ExamAccessAccommodation::ScopeAcademicYear) {
                $openQuery->where('academic_year_id', $validated['academic_year_id']);
            } else {
                $openQuery->where('term_id', $validated['term_id']);
            }

            if ($openQuery->exists()) {
                throw new RuntimeException('An open exam-access accommodation already exists for this scope.');
            }

            $accommodation = ExamAccessAccommodation::query()->create([
                ...$validated,
                'status' => ExamAccessAccommodation::StatusPending,
                'requested_by' => $actor->id,
                'requested_at' => $timestamp,
            ]);

            $this->recordActivity($accommodation, 'exam_access_accommodation_submitted', $actor, [
                'status_after' => ExamAccessAccommodation::StatusPending,
                'scope' => $accommodation->scope,
                'basis' => $accommodation->basis,
            ], $timestamp);

            return $accommodation->fresh();
        });
    }

    public function approve(
        ExamAccessAccommodation $accommodation,
        User $reviewer,
        string $reviewReason,
        ?CarbonImmutable $reviewedAt = null,
    ): ExamAccessAccommodation {
        $this->authorizeReviewer($reviewer);
        $reviewReason = $this->requiredText($reviewReason, 'A verification or approval note is required.');
        $timestamp = $reviewedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($accommodation, $reviewer, $reviewReason, $timestamp): ExamAccessAccommodation {
            $locked = $this->lock($accommodation);
            $this->assertStatus($locked, [ExamAccessAccommodation::StatusPending]);
            $locked->forceFill([
                'status' => ExamAccessAccommodation::StatusApproved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $timestamp,
                'review_reason' => $reviewReason,
            ])->save();

            $this->recordActivity($locked, 'exam_access_accommodation_approved', $reviewer, [
                'status_after' => ExamAccessAccommodation::StatusApproved,
                'basis' => $locked->basis,
            ], $timestamp);
            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'exam_access_accommodation_approved',
                subject: 'Exam access accommodation approved',
                body: 'Your exam access accommodation was approved for the recorded validity period. Your financial balance remains due.',
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function reject(
        ExamAccessAccommodation $accommodation,
        User $reviewer,
        string $reason,
        ?CarbonImmutable $reviewedAt = null,
    ): ExamAccessAccommodation {
        $this->authorizeReviewer($reviewer);
        $reason = $this->requiredText($reason, 'A rejection reason is required.');
        $timestamp = $reviewedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($accommodation, $reviewer, $reason, $timestamp): ExamAccessAccommodation {
            $locked = $this->lock($accommodation);
            $this->assertStatus($locked, [ExamAccessAccommodation::StatusPending]);
            $locked->forceFill([
                'status' => ExamAccessAccommodation::StatusRejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $timestamp,
                'review_reason' => $reason,
            ])->save();

            $this->recordActivity($locked, 'exam_access_accommodation_rejected', $reviewer, [
                'status_after' => ExamAccessAccommodation::StatusRejected,
                'rejection_reason' => $reason,
            ], $timestamp);
            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'exam_access_accommodation_rejected',
                subject: 'Exam access accommodation rejected',
                body: "Your exam access accommodation was rejected. Reason: {$reason}",
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function revoke(
        ExamAccessAccommodation $accommodation,
        User $reviewer,
        string $reason,
        ?CarbonImmutable $revokedAt = null,
    ): ExamAccessAccommodation {
        $this->authorizeReviewer($reviewer);
        $reason = $this->requiredText($reason, 'A revocation reason is required.');
        $timestamp = $revokedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($accommodation, $reviewer, $reason, $timestamp): ExamAccessAccommodation {
            $locked = $this->lock($accommodation);
            $this->assertStatus($locked, [ExamAccessAccommodation::StatusApproved]);
            $locked->forceFill([
                'status' => ExamAccessAccommodation::StatusRevoked,
                'revoked_by' => $reviewer->id,
                'revoked_at' => $timestamp,
                'revocation_reason' => $reason,
            ])->save();

            $this->recordActivity($locked, 'exam_access_accommodation_revoked', $reviewer, [
                'status_after' => ExamAccessAccommodation::StatusRevoked,
                'revocation_reason' => $reason,
            ], $timestamp);
            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'exam_access_accommodation_revoked',
                subject: 'Exam access accommodation revoked',
                body: "Your exam access accommodation was revoked. Reason: {$reason}",
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateSubmission(array $data, StudentProfile $studentProfile): array
    {
        $errors = [];
        $term = Term::query()->with('academicYear')->find((int) ($data['term_id'] ?? 0));
        $enrollment = Enrollment::query()->find((int) ($data['enrollment_id'] ?? 0));
        $basis = trim((string) ($data['basis'] ?? ''));
        $requestReason = $this->nullableText($data['request_reason'] ?? null);
        $certifyingOffice = $this->nullableText($data['certifying_office'] ?? null);
        $certificationReference = $this->nullableText($data['certification_reference'] ?? null);
        $evidencePath = $this->nullableText($data['evidence_path'] ?? null);

        if (! $term instanceof Term || ! $term->academicYear instanceof AcademicYear) {
            $errors['term_id'] = 'Select a term linked to an academic year.';
        }

        if (! $enrollment instanceof Enrollment || $enrollment->student_profile_id !== $studentProfile->id) {
            $errors['enrollment_id'] = 'Select an enrollment belonging to the student.';
        } elseif ($term instanceof Term && $enrollment->term_id !== $term->id) {
            $errors['enrollment_id'] = 'The enrollment must belong to the selected term.';
        }

        if (! array_key_exists($basis, ExamAccessAccommodation::basisOptions())) {
            $errors['basis'] = 'Select a valid exam accommodation basis.';
        }

        if ($basis === ExamAccessAccommodation::BasisRa11984Certification) {
            if ($certifyingOffice === null) {
                $errors['certifying_office'] = 'The certifying social-welfare office is required.';
            }

            if ($certificationReference === null && $evidencePath === null) {
                $errors['certification_reference'] = 'Provide a certification reference or private evidence file.';
            }
        }

        if ($basis === ExamAccessAccommodation::BasisInstitutionalDiscretion && $requestReason === null) {
            $errors['request_reason'] = 'A documented reason is required for institutional discretion.';
        }

        $academicYear = $term?->academicYear;
        $scope = $studentProfile->education_level === 'shs'
            ? ExamAccessAccommodation::ScopeAcademicYear
            : ExamAccessAccommodation::ScopeTerm;
        $defaultStart = $scope === ExamAccessAccommodation::ScopeAcademicYear
            ? $academicYear?->school_year_start_date
            : $term?->term_start_date;
        $defaultEnd = $scope === ExamAccessAccommodation::ScopeAcademicYear
            ? $academicYear?->school_year_end_date
            : $term?->term_end_date;
        $validFrom = $this->parseDate($data['valid_from'] ?? $defaultStart);
        $validUntil = $this->parseDate($data['valid_until'] ?? $defaultEnd);

        if ($validFrom === null) {
            $errors['valid_from'] = 'A validity start date is required.';
        }

        if ($validUntil === null || ($validFrom !== null && $validUntil->lessThan($validFrom))) {
            $errors['valid_until'] = 'The validity end date must be on or after the start date.';
        }

        $promissoryNoteId = filled($data['promissory_note_id'] ?? null) ? (int) $data['promissory_note_id'] : null;

        if ($promissoryNoteId !== null && ! PromissoryNote::query()
            ->whereKey($promissoryNoteId)
            ->where('student_profile_id', $studentProfile->id)
            ->exists()) {
            $errors['promissory_note_id'] = 'The linked promissory note must belong to the selected student.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'student_profile_id' => $studentProfile->id,
            'academic_year_id' => $scope === ExamAccessAccommodation::ScopeAcademicYear ? $academicYear?->id : null,
            'term_id' => $scope === ExamAccessAccommodation::ScopeTerm ? $term?->id : null,
            'enrollment_id' => $enrollment?->id,
            'promissory_note_id' => $promissoryNoteId,
            'scope' => $scope,
            'basis' => $basis,
            'request_reason' => $requestReason,
            'certifying_office' => $certifyingOffice,
            'certification_reference' => $certificationReference,
            'certified_at' => $this->parseDate($data['certified_at'] ?? null)?->toDateString(),
            'evidence_disk' => $this->nullableText($data['evidence_disk'] ?? null),
            'evidence_path' => $evidencePath,
            'evidence_file_name' => $this->nullableText($data['evidence_file_name'] ?? null),
            'evidence_mime_type' => $this->nullableText($data['evidence_mime_type'] ?? null),
            'evidence_file_size' => filled($data['evidence_file_size'] ?? null) ? (int) $data['evidence_file_size'] : null,
            'valid_from' => $validFrom?->toDateString(),
            'valid_until' => $validUntil?->toDateString(),
        ];
    }

    private function authorizeSubmission(StudentProfile $studentProfile, User $actor): void
    {
        $ownsProfile = (int) $studentProfile->user_id === (int) $actor->id;
        $isEligibleOwner = $ownsProfile && $actor->hasAnyRole(['applicant', 'student']);

        if (! $isEligibleOwner && ! $actor->can('approve-promissory-notes')) {
            throw new AuthorizationException('Only the student owner or Accounting can submit an exam accommodation request.');
        }
    }

    private function authorizeReviewer(User $reviewer): void
    {
        if (! $reviewer->can('approve-promissory-notes')) {
            throw new AuthorizationException('Only authorized Accounting staff can review exam accommodations.');
        }
    }

    private function lock(ExamAccessAccommodation $accommodation): ExamAccessAccommodation
    {
        return ExamAccessAccommodation::query()->lockForUpdate()->findOrFail($accommodation->id);
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function assertStatus(ExamAccessAccommodation $accommodation, array $allowedStatuses): void
    {
        if (! in_array($accommodation->status, $allowedStatuses, true)) {
            throw new RuntimeException("Invalid exam accommodation transition from [{$accommodation->status}].");
        }
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse((string) $value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function requiredText(mixed $value, string $message): string
    {
        $value = $this->nullableText($value);

        if ($value === null) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function notifyStudent(ExamAccessAccommodation $accommodation, GeneralSystemNotification $notification): void
    {
        $student = StudentProfile::query()->with('user')->find($accommodation->student_profile_id)?->user;

        if ($student instanceof User) {
            $student->notify($notification);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationMetadata(ExamAccessAccommodation $accommodation): array
    {
        return [
            'exam_access_accommodation_id' => $accommodation->id,
            'scope' => $accommodation->scope,
            'academic_year_id' => $accommodation->academic_year_id,
            'term_id' => $accommodation->term_id,
            'status' => $accommodation->status,
            'valid_from' => $accommodation->valid_from?->toDateString(),
            'valid_until' => $accommodation->valid_until?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        ExamAccessAccommodation $accommodation,
        string $event,
        ?User $actor,
        array $properties,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'exam_access_accommodation',
            'description' => 'Exam access accommodation lifecycle transition.',
            'subject_type' => ExamAccessAccommodation::class,
            'subject_id' => $accommodation->id,
            'event' => $event,
            'causer_type' => $actor instanceof User ? User::class : null,
            'causer_id' => $actor?->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
