<?php

namespace App\Actions\Grades;

use App\Enums\GradeCorrectionStatus;
use App\Exceptions\InvalidGradeException;
use App\Exceptions\MissingGradePeriodException;
use App\Models\Grade;
use App\Models\GradeCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GradeCorrectionService
{
    public function __construct(
        private readonly CollegeGradingService $collegeGrading,
    ) {}

    /**
     * @param  list<string>  $attachmentPaths
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function submit(
        User $student,
        int $subjectId,
        int $termId,
        string $requestedAction,
        string $reason,
        ?int $gradeId = null,
        ?string $assessmentComponent = null,
        array $attachmentPaths = [],
        ?CarbonImmutable $submittedAt = null,
    ): GradeCorrection {
        $timestamp = $submittedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use (
            $student,
            $subjectId,
            $termId,
            $requestedAction,
            $reason,
            $gradeId,
            $assessmentComponent,
            $attachmentPaths,
            $timestamp,
        ): GradeCorrection {
            $this->assertStudentCanSubmit($student);
            $this->assertText($requestedAction, 'requested_action', 500);
            $this->assertText($reason, 'reason', 250);

            $normalizedAttachments = $this->normalizeAttachmentPaths($attachmentPaths);
            $grade = $this->visibleGrade($student, $subjectId, $termId, $gradeId);

            if (! $grade instanceof Grade) {
                $this->assertStudentSubjectVisible($student, $subjectId, $termId);
            }

            $this->assertNoDuplicateActiveCorrection($student, $subjectId, $termId, $grade?->id);

            $correction = GradeCorrection::query()->create([
                'user_id' => $student->id,
                'grade_id' => $grade?->id,
                'subject_id' => $subjectId,
                'term_id' => $termId,
                'assessment_component' => $assessmentComponent,
                'current_grade' => $grade?->grade,
                'requested_action' => trim($requestedAction),
                'reason' => trim($reason),
                'attachment_paths' => $normalizedAttachments === [] ? null : $normalizedAttachments,
                'status' => GradeCorrectionStatus::Submitted,
                'creator_id' => $student->id,
            ]);

            $this->recordCorrectionAudit(
                correction: $correction,
                actor: $student,
                event: 'grade_correction_submitted',
                oldStatus: null,
                newStatus: GradeCorrectionStatus::Submitted,
                reason: $reason,
                recordedAt: $timestamp,
            );

            return $correction->fresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function startReview(GradeCorrection $correction, User $registrar, ?CarbonImmutable $reviewedAt = null): GradeCorrectionResult
    {
        $timestamp = $reviewedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($correction, $registrar, $timestamp): GradeCorrectionResult {
            $lockedCorrection = $this->lockedCorrection($correction);

            $this->assertRegistrarCanManage($registrar);
            $this->assertStatus($lockedCorrection, GradeCorrectionStatus::Submitted, 'Only submitted grade corrections can move under review.');

            $oldStatus = $this->status($lockedCorrection);

            $lockedCorrection->forceFill([
                'status' => GradeCorrectionStatus::UnderReview,
                'assigned_to' => $registrar->id,
            ])->save();

            $lockedCorrection->refresh();

            $this->recordCorrectionAudit(
                correction: $lockedCorrection,
                actor: $registrar,
                event: 'grade_correction_under_review',
                oldStatus: $oldStatus,
                newStatus: GradeCorrectionStatus::UnderReview,
                reason: null,
                recordedAt: $timestamp,
            );

            return GradeCorrectionResult::underReview($lockedCorrection);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reject(GradeCorrection $correction, User $registrar, string $reason, ?CarbonImmutable $rejectedAt = null): GradeCorrectionResult
    {
        $timestamp = $rejectedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($correction, $registrar, $reason, $timestamp): GradeCorrectionResult {
            $lockedCorrection = $this->lockedCorrection($correction);

            $this->assertRegistrarCanManage($registrar);
            $this->assertText($reason, 'reason', 250);
            $this->assertNotTerminal($lockedCorrection);

            $oldStatus = $this->status($lockedCorrection);

            if (! in_array($oldStatus, [GradeCorrectionStatus::Submitted, GradeCorrectionStatus::UnderReview], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted or under review grade corrections can be rejected.',
                ]);
            }

            $lockedCorrection->forceFill([
                'status' => GradeCorrectionStatus::Rejected,
                'assigned_to' => $registrar->id,
                'resolved_at' => $timestamp,
            ])->save();

            $lockedCorrection->refresh();

            $this->recordCorrectionAudit(
                correction: $lockedCorrection,
                actor: $registrar,
                event: 'grade_correction_rejected',
                oldStatus: $oldStatus,
                newStatus: GradeCorrectionStatus::Rejected,
                reason: $reason,
                recordedAt: $timestamp,
            );

            return GradeCorrectionResult::rejected($lockedCorrection);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolveWithoutGradeChange(
        GradeCorrection $correction,
        User $registrar,
        string $resolutionNotes,
        ?CarbonImmutable $resolvedAt = null,
    ): GradeCorrectionResult {
        $timestamp = $resolvedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($correction, $registrar, $resolutionNotes, $timestamp): GradeCorrectionResult {
            $lockedCorrection = $this->lockedCorrection($correction);

            $this->assertRegistrarCanManage($registrar);
            $this->assertText($resolutionNotes, 'resolution_notes', 500);
            $this->assertStatus($lockedCorrection, GradeCorrectionStatus::UnderReview, 'Only under review grade corrections can be resolved.');

            $oldStatus = $this->status($lockedCorrection);

            $lockedCorrection->forceFill([
                'status' => GradeCorrectionStatus::Resolved,
                'assigned_to' => $registrar->id,
                'resolved_at' => $timestamp,
            ])->save();

            $lockedCorrection->refresh();

            $this->recordCorrectionAudit(
                correction: $lockedCorrection,
                actor: $registrar,
                event: 'grade_correction_resolved',
                oldStatus: $oldStatus,
                newStatus: GradeCorrectionStatus::Resolved,
                reason: $resolutionNotes,
                recordedAt: $timestamp,
            );

            return GradeCorrectionResult::resolved($lockedCorrection);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function approveOfficialGradeChange(
        GradeCorrection $correction,
        User $academicHead,
        string $approvalReason,
        ?CarbonImmutable $approvedAt = null,
    ): GradeCorrectionResult {
        $timestamp = $approvedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($correction, $academicHead, $approvalReason, $timestamp): GradeCorrectionResult {
            $lockedCorrection = $this->lockedCorrection($correction);

            $this->assertAcademicHeadCanAuthorize($academicHead);
            $this->assertText($approvalReason, 'approval_reason', 500);
            $this->assertStatus($lockedCorrection, GradeCorrectionStatus::UnderReview, 'Only under review grade corrections can receive Academic Head approval.');
            $this->assertLinkedOfficialGradeCorrection($lockedCorrection);
            $this->assertNoAcademicHeadReviewDecision($lockedCorrection);

            $lockedCorrection->forceFill([
                'academic_head_review_status' => GradeCorrection::AcademicHeadReviewApproved,
                'academic_head_reviewed_by' => $academicHead->id,
                'academic_head_reviewed_at' => $timestamp,
                'academic_head_review_note' => trim($approvalReason),
            ])->save();

            $lockedCorrection->refresh();

            $this->recordCorrectionAudit(
                correction: $lockedCorrection,
                actor: $academicHead,
                event: 'grade_correction_academic_head_approved',
                oldStatus: GradeCorrectionStatus::UnderReview,
                newStatus: GradeCorrectionStatus::UnderReview,
                reason: $approvalReason,
                recordedAt: $timestamp,
                properties: [
                    'academic_head_review_status' => GradeCorrection::AcademicHeadReviewApproved,
                    'academic_head_id' => $academicHead->id,
                ],
            );

            return GradeCorrectionResult::underReview($lockedCorrection);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function rejectOfficialGradeChange(
        GradeCorrection $correction,
        User $academicHead,
        string $rejectionReason,
        ?CarbonImmutable $rejectedAt = null,
    ): GradeCorrectionResult {
        $timestamp = $rejectedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($correction, $academicHead, $rejectionReason, $timestamp): GradeCorrectionResult {
            $lockedCorrection = $this->lockedCorrection($correction);

            $this->assertAcademicHeadCanAuthorize($academicHead);
            $this->assertText($rejectionReason, 'rejection_reason', 500);
            $this->assertStatus($lockedCorrection, GradeCorrectionStatus::UnderReview, 'Only under review grade corrections can be rejected by the Academic Head.');
            $this->assertLinkedOfficialGradeCorrection($lockedCorrection);
            $this->assertNoAcademicHeadReviewDecision($lockedCorrection);

            $oldStatus = $this->status($lockedCorrection);

            $lockedCorrection->forceFill([
                'status' => GradeCorrectionStatus::Rejected,
                'academic_head_review_status' => GradeCorrection::AcademicHeadReviewRejected,
                'academic_head_reviewed_by' => $academicHead->id,
                'academic_head_reviewed_at' => $timestamp,
                'academic_head_review_note' => trim($rejectionReason),
                'resolved_at' => $timestamp,
            ])->save();

            $lockedCorrection->refresh();

            $this->recordCorrectionAudit(
                correction: $lockedCorrection,
                actor: $academicHead,
                event: 'grade_correction_academic_head_rejected',
                oldStatus: $oldStatus,
                newStatus: GradeCorrectionStatus::Rejected,
                reason: $rejectionReason,
                recordedAt: $timestamp,
                properties: [
                    'academic_head_review_status' => GradeCorrection::AcademicHeadReviewRejected,
                    'academic_head_id' => $academicHead->id,
                ],
            );

            return GradeCorrectionResult::rejected($lockedCorrection);
        });
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $gradeAttributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolveWithGradeChange(
        GradeCorrection $correction,
        User $registrar,
        array $gradeAttributes,
        string $resolutionNotes,
        ?CarbonImmutable $resolvedAt = null,
    ): GradeCorrectionResult {
        $timestamp = $resolvedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use (
            $correction,
            $registrar,
            $gradeAttributes,
            $resolutionNotes,
            $timestamp,
        ): GradeCorrectionResult {
            $lockedCorrection = $this->lockedCorrection($correction);

            $this->assertRegistrarCanManage($registrar);
            $this->assertText($resolutionNotes, 'resolution_notes', 500);
            $this->assertStatus($lockedCorrection, GradeCorrectionStatus::UnderReview, 'Only under review grade corrections can be resolved.');
            $this->assertLinkedOfficialGradeCorrection($lockedCorrection);
            $this->assertAcademicHeadApprovalGranted($lockedCorrection);

            $academicHead = $lockedCorrection->academicHeadReviewer()->firstOrFail();
            $approvalReason = (string) $lockedCorrection->academic_head_review_note;

            $lockedGrade = Grade::query()
                ->whereKey($lockedCorrection->grade_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldGrade = $this->gradeSnapshot($lockedGrade);
            $normalizedGradeAttributes = $this->officialGradeChangeAttributes($lockedGrade, $gradeAttributes);

            $lockedGrade->forceFill($normalizedGradeAttributes);

            if (! $lockedGrade->isDirty(array_keys($normalizedGradeAttributes))) {
                throw ValidationException::withMessages([
                    'grade' => 'At least one grade value must change before using the official grade change path.',
                ]);
            }

            $lockedGrade->save();
            $lockedGrade->refresh();

            $oldStatus = $this->status($lockedCorrection);

            $lockedCorrection->forceFill([
                'status' => GradeCorrectionStatus::Resolved,
                'assigned_to' => $registrar->id,
                'resolved_at' => $timestamp,
            ])->save();

            $lockedCorrection->refresh();

            $this->recordGradeChangeAudit(
                grade: $lockedGrade,
                correction: $lockedCorrection,
                registrar: $registrar,
                academicHead: $academicHead,
                oldGrade: $oldGrade,
                newGrade: $this->gradeSnapshot($lockedGrade),
                approvalReason: $approvalReason,
                resolutionNotes: $resolutionNotes,
                recordedAt: $timestamp,
            );

            $this->recordCorrectionAudit(
                correction: $lockedCorrection,
                actor: $registrar,
                event: 'grade_correction_resolved_with_grade_change',
                oldStatus: $oldStatus,
                newStatus: GradeCorrectionStatus::Resolved,
                reason: $resolutionNotes,
                recordedAt: $timestamp,
                properties: [
                    'academic_head_id' => $academicHead->id,
                    'grade_id' => $lockedGrade->id,
                ],
            );

            return GradeCorrectionResult::resolvedWithGradeChange($lockedCorrection);
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function assertStudentCanSubmit(User $student): void
    {
        if ($student->hasRole('student') && $student->can('request-grade-corrections')) {
            return;
        }

        throw new AuthorizationException('Only students can submit grade correction requests.');
    }

    /**
     * @throws AuthorizationException
     */
    private function assertRegistrarCanManage(User $registrar): void
    {
        if ($registrar->hasRole('registrar') && $registrar->can('manage-grade-corrections')) {
            return;
        }

        throw new AuthorizationException('Only Registrar staff can manage grade correction requests.');
    }

    /**
     * @throws AuthorizationException
     */
    private function assertAcademicHeadCanAuthorize(User $academicHead): void
    {
        if ($academicHead->hasRole('academic-head') && $academicHead->can('authorize-overrides')) {
            return;
        }

        throw new AuthorizationException('Only the Academic Head can authorize official grade corrections.');
    }

    /**
     * @throws ValidationException
     */
    private function assertLinkedOfficialGradeCorrection(GradeCorrection $correction): void
    {
        if ($correction->grade_id !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'grade_id' => 'A linked grade is required before an official grade change can be reviewed.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertNoAcademicHeadReviewDecision(GradeCorrection $correction): void
    {
        if (! $correction->hasAcademicHeadApproval() && ! $correction->hasAcademicHeadRejection()) {
            return;
        }

        throw ValidationException::withMessages([
            'academic_head_review_status' => 'This grade correction already has an Academic Head decision.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertAcademicHeadApprovalGranted(GradeCorrection $correction): void
    {
        if ($correction->hasAcademicHeadApproval()) {
            return;
        }

        throw ValidationException::withMessages([
            'academic_head_review_status' => 'Academic Head approval is required before the Registrar can apply an official grade change.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertText(string $value, string $field, int $max): void
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                $field => 'This field is required.',
            ]);
        }

        if (mb_strlen($trimmed) > $max) {
            throw ValidationException::withMessages([
                $field => "This field may not be greater than {$max} characters.",
            ]);
        }
    }

    /**
     * @param  list<string>  $attachmentPaths
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function normalizeAttachmentPaths(array $attachmentPaths): array
    {
        if (count($attachmentPaths) > 3) {
            throw ValidationException::withMessages([
                'attachments' => 'Grade correction requests can include at most 3 attachments.',
            ]);
        }

        $normalized = [];

        foreach ($attachmentPaths as $path) {
            if (! is_string($path) || trim($path) === '') {
                throw ValidationException::withMessages([
                    'attachments' => 'Attachment paths must be non-empty private disk paths.',
                ]);
            }

            $normalized[] = trim($path);
        }

        return $normalized;
    }

    /**
     * @throws AuthorizationException
     */
    private function visibleGrade(User $student, int $subjectId, int $termId, ?int $gradeId): ?Grade
    {
        if ($gradeId === null) {
            return null;
        }

        $grade = Grade::query()
            ->whereKey($gradeId)
            ->where('subject_id', $subjectId)
            ->where('term_id', $termId)
            ->lockForUpdate()
            ->first();

        if (! $grade instanceof Grade || ! $this->studentOwnsGrade($student, $grade)) {
            throw new AuthorizationException('The selected grade is not visible to this student.');
        }

        return $grade;
    }

    private function studentOwnsGrade(User $student, Grade $grade): bool
    {
        return DB::table('grades')
            ->join('enrollments', 'enrollments.id', '=', 'grades.enrollment_id')
            ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_profile_id')
            ->where('grades.id', $grade->id)
            ->where('student_profiles.user_id', $student->id)
            ->exists();
    }

    /**
     * @throws AuthorizationException
     */
    private function assertStudentSubjectVisible(User $student, int $subjectId, int $termId): void
    {
        $isVisible = DB::table('enrollment_subjects')
            ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
            ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_profile_id')
            ->where('student_profiles.user_id', $student->id)
            ->where('enrollments.term_id', $termId)
            ->where('enrollment_subjects.subject_id', $subjectId)
            ->exists();

        if (! $isVisible) {
            throw new AuthorizationException('The selected subject is not visible to this student.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoDuplicateActiveCorrection(User $student, int $subjectId, int $termId, ?int $gradeId): void
    {
        $activeStatuses = [
            GradeCorrectionStatus::Submitted->value,
            GradeCorrectionStatus::UnderReview->value,
        ];

        $query = GradeCorrection::query()
            ->where('user_id', $student->id)
            ->where('subject_id', $subjectId)
            ->where('term_id', $termId)
            ->whereIn('status', $activeStatuses);

        if ($gradeId === null) {
            $query->whereNull('grade_id');
        } else {
            $query->where('grade_id', $gradeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'grade_correction' => 'An active grade correction already exists for this grade, subject, and term.',
            ]);
        }
    }

    private function lockedCorrection(GradeCorrection $correction): GradeCorrection
    {
        return GradeCorrection::query()
            ->whereKey($correction->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function status(GradeCorrection $correction): GradeCorrectionStatus
    {
        if ($correction->status instanceof GradeCorrectionStatus) {
            return $correction->status;
        }

        return GradeCorrectionStatus::tryFrom((string) $correction->status)
            ?? throw new RuntimeException('Unsupported grade correction status.');
    }

    /**
     * @throws ValidationException
     */
    private function assertStatus(GradeCorrection $correction, GradeCorrectionStatus $status, string $message): void
    {
        if ($this->status($correction) === $status) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => $message,
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertNotTerminal(GradeCorrection $correction): void
    {
        if (! $this->status($correction)->isTerminal()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Terminal grade correction requests cannot be changed.',
        ]);
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $gradeAttributes
     * @return array<string, bool|float|int|string|null>
     *
     * @throws ValidationException
     */
    private function officialGradeChangeAttributes(Grade $grade, array $gradeAttributes): array
    {
        return $this->collegeOfficialGradeChangeAttributes($gradeAttributes);
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $gradeAttributes
     * @return array{prelim_grade:string, midterm_grade:string, final_grade:string, grade:string, remarks:string, is_inc:false, inc_expires_at:null}
     *
     * @throws ValidationException
     */
    private function collegeOfficialGradeChangeAttributes(array $gradeAttributes): array
    {
        $this->assertOnlyGradeOverrideFields($gradeAttributes, ['college_prelim', 'college_midterm', 'college_final']);

        try {
            $result = $this->collegeGrading->calculateFinalGrade([
                'prelim' => $gradeAttributes['college_prelim'] ?? null,
                'midterm' => $gradeAttributes['college_midterm'] ?? null,
                'final' => $gradeAttributes['college_final'] ?? null,
            ]);
        } catch (InvalidGradeException|MissingGradePeriodException $exception) {
            throw ValidationException::withMessages([
                'grade' => $exception->getMessage(),
            ]);
        }

        return [
            'prelim_grade' => $result['prelim'],
            'midterm_grade' => $result['midterm'],
            'final_grade' => $result['final_raw_average'],
            'grade' => $result['equivalent_grade'],
            'remarks' => $result['remarks'],
            'is_inc' => false,
            'inc_expires_at' => null,
        ];
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $gradeAttributes
     * @param  list<string>  $allowedFields
     *
     * @throws ValidationException
     */
    private function assertOnlyGradeOverrideFields(array $gradeAttributes, array $allowedFields): void
    {
        $unknown = array_diff(array_keys($gradeAttributes), $allowedFields);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'grade' => 'Unsupported grade override fields were provided for this grading scheme.',
            ]);
        }

        foreach ($allowedFields as $field) {
            if (! array_key_exists($field, $gradeAttributes) || $gradeAttributes[$field] === null || $gradeAttributes[$field] === '') {
                throw ValidationException::withMessages([
                    $field => 'This grade field is required for an official grade change.',
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function gradeSnapshot(Grade $grade): array
    {
        return [
            'prelim_grade' => $grade->prelim_grade,
            'midterm_grade' => $grade->midterm_grade,
            'final_grade' => $grade->final_grade,
            'grade' => $grade->grade,
            'remarks' => $grade->remarks,
            'is_inc' => $grade->is_inc,
            'inc_expires_at' => $grade->inc_expires_at?->toIso8601String(),
            'is_finalized' => $grade->is_finalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordCorrectionAudit(
        GradeCorrection $correction,
        User $actor,
        string $event,
        ?GradeCorrectionStatus $oldStatus,
        GradeCorrectionStatus $newStatus,
        ?string $reason,
        CarbonImmutable $recordedAt,
        array $properties = [],
    ): void {
        activity('grade_corrections')
            ->causedBy($actor)
            ->performedOn($correction)
            ->event($event)
            ->withProperties($properties + [
                'reason' => $reason,
                'old_status' => $oldStatus?->value,
                'new_status' => $newStatus->value,
                'student_id' => $correction->user_id,
                'grade_id' => $correction->grade_id,
                'subject_id' => $correction->subject_id,
                'term_id' => $correction->term_id,
            ])
            ->createdAt($recordedAt)
            ->log('Grade correction state changed.');
    }

    /**
     * @param  array<string, mixed>  $oldGrade
     * @param  array<string, mixed>  $newGrade
     */
    private function recordGradeChangeAudit(
        Grade $grade,
        GradeCorrection $correction,
        User $registrar,
        User $academicHead,
        array $oldGrade,
        array $newGrade,
        string $approvalReason,
        string $resolutionNotes,
        CarbonImmutable $recordedAt,
    ): void {
        activity('grades')
            ->causedBy($academicHead)
            ->performedOn($grade)
            ->event('grade_corrected_by_override')
            ->withProperties([
                'grade_correction_id' => $correction->id,
                'approval_reason' => $approvalReason,
                'resolution_notes' => $resolutionNotes,
                'old_grade' => $oldGrade,
                'new_grade' => $newGrade,
                'authorizer_id' => $academicHead->id,
                'registrar_id' => $registrar->id,
                'enrollment_id' => $grade->enrollment_id,
                'enrollment_subject_id' => $grade->enrollment_subject_id,
                'subject_id' => $grade->subject_id,
            ])
            ->createdAt($recordedAt)
            ->log('Official grade corrected through Academic Head override.');
    }
}
