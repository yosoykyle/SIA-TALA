<?php

namespace App\Actions\Grades;

use App\Models\Grade;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class GradeFinalizationService
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function finalize(Grade $grade, User $actor, ?CarbonImmutable $finalizedAt = null): GradeFinalizationResult
    {
        $timestamp = $finalizedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($grade, $actor, $timestamp): GradeFinalizationResult {
            $lockedGrade = $this->lockedGrade($grade);

            if ($lockedGrade->is_finalized) {
                return GradeFinalizationResult::alreadyFinalized($lockedGrade);
            }

            $this->assertCanNormalFinalize($lockedGrade, $actor);
            $this->assertGradeCanBeFinalized($lockedGrade);

            $oldGrade = $this->gradeSnapshot($lockedGrade);

            $lockedGrade->forceFill([
                'is_finalized' => true,
                'finalized_at' => $timestamp,
                'finalized_by' => $actor->id,
            ])->save();

            $lockedGrade->refresh();

            $this->recordAudit(
                grade: $lockedGrade,
                actor: $actor,
                event: 'grade_finalized',
                reason: null,
                oldGrade: $oldGrade,
                newGrade: $this->gradeSnapshot($lockedGrade),
                recordedAt: $timestamp,
            );

            return GradeFinalizationResult::finalized($lockedGrade);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function forceFinalize(Grade $grade, User $actor, string $reason, ?CarbonImmutable $finalizedAt = null): GradeFinalizationResult
    {
        $timestamp = $finalizedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($grade, $actor, $reason, $timestamp): GradeFinalizationResult {
            $lockedGrade = $this->lockedGrade($grade);

            if ($lockedGrade->is_finalized) {
                return GradeFinalizationResult::alreadyFinalized($lockedGrade);
            }

            $this->assertCanAuthorizeOverride($actor);
            $this->assertReason($reason, 'A reason is required to force-finalize a grade.');
            $this->assertGradeCanBeFinalized($lockedGrade);

            $oldGrade = $this->gradeSnapshot($lockedGrade);

            $lockedGrade->forceFill([
                'is_finalized' => true,
                'finalized_at' => $timestamp,
                'finalized_by' => $actor->id,
            ])->save();

            $lockedGrade->refresh();

            $this->recordAudit(
                grade: $lockedGrade,
                actor: $actor,
                event: 'grade_force_finalized',
                reason: $reason,
                oldGrade: $oldGrade,
                newGrade: $this->gradeSnapshot($lockedGrade),
                recordedAt: $timestamp,
            );

            return GradeFinalizationResult::finalizedByOverride($lockedGrade);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reopen(Grade $grade, User $actor, string $reason, ?CarbonImmutable $reopenedAt = null): GradeFinalizationResult
    {
        $timestamp = $reopenedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($grade, $actor, $reason, $timestamp): GradeFinalizationResult {
            $lockedGrade = $this->lockedGrade($grade);

            $this->assertCanAuthorizeOverride($actor);
            $this->assertReason($reason, 'A reason is required to reopen a finalized grade.');

            if (! $lockedGrade->is_finalized) {
                throw ValidationException::withMessages([
                    'grade' => 'Only finalized grades can be reopened.',
                ]);
            }

            $oldGrade = $this->gradeSnapshot($lockedGrade);

            $lockedGrade->forceFill([
                'is_finalized' => false,
                'reopened_at' => $timestamp,
                'reopened_by' => $actor->id,
            ])->save();

            $lockedGrade->refresh();

            $this->recordAudit(
                grade: $lockedGrade,
                actor: $actor,
                event: 'grade_reopened',
                reason: $reason,
                oldGrade: $oldGrade,
                newGrade: $this->gradeSnapshot($lockedGrade),
                recordedAt: $timestamp,
            );

            return GradeFinalizationResult::reopened($lockedGrade);
        });
    }

    private function lockedGrade(Grade $grade): Grade
    {
        return Grade::query()
            ->whereKey($grade->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCanNormalFinalize(Grade $grade, User $actor): void
    {
        if (! $actor->hasRole('faculty') || ! $actor->can('finalize-grades')) {
            throw new AuthorizationException('Only faculty with finalization permission can finalize their grade sheets.');
        }

        if ($this->isAssignedFacultyFor($grade, $actor)) {
            return;
        }

        throw new AuthorizationException('Only the assigned faculty can finalize this grade sheet.');
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCanAuthorizeOverride(User $actor): void
    {
        if ($actor->hasRole('academic-head') && $actor->can('authorize-overrides')) {
            return;
        }

        throw new AuthorizationException('Only the Academic Head can authorize grade finalization overrides.');
    }

    /**
     * @throws ValidationException
     */
    private function assertReason(string $reason, string $message): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => $message,
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertGradeCanBeFinalized(Grade $grade): void
    {
        if ($grade->is_inc) {
            if ($grade->remarks === 'inc' && $grade->inc_expires_at !== null) {
                return;
            }

            throw ValidationException::withMessages([
                'grade' => 'Incomplete grades require INC remarks and an expiry date before finalization.',
            ]);
        }

        foreach (['prelim_grade', 'midterm_grade', 'final_grade', 'grade', 'remarks'] as $field) {
            if ($grade->{$field} === null || $grade->{$field} === '') {
                throw ValidationException::withMessages([
                    'grade' => 'Grade sheet is incomplete and cannot be finalized.',
                ]);
            }
        }
    }

    private function isAssignedFacultyFor(Grade $grade, User $actor): bool
    {
        $enrollmentSubject = $this->enrollmentSubjectFor($grade);

        if (! $enrollmentSubject instanceof stdClass) {
            return false;
        }

        if ($enrollmentSubject->section_meeting_id !== null && DB::table('section_meetings')
            ->where('id', $enrollmentSubject->section_meeting_id)
            ->where('subject_id', $grade->subject_id)
            ->where('faculty_id', $actor->id)
            ->exists()) {
            return true;
        }

        if ($enrollmentSubject->section_id === null) {
            return false;
        }

        return DB::table('section_teacher')
            ->where('section_id', $enrollmentSubject->section_id)
            ->where('subject_id', $grade->subject_id)
            ->where('user_id', $actor->id)
            ->exists();
    }

    private function enrollmentSubjectFor(Grade $grade): ?stdClass
    {
        return DB::table('enrollment_subjects')
            ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
            ->where('enrollment_subjects.enrollment_id', $grade->enrollment_id)
            ->where('enrollment_subjects.subject_id', $grade->subject_id)
            ->select([
                'enrollment_subjects.id',
                'enrollment_subjects.section_meeting_id',
                'enrollments.section_id',
            ])
            ->first();
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
            'is_finalized' => $grade->is_finalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $oldGrade
     * @param  array<string, mixed>  $newGrade
     */
    private function recordAudit(
        Grade $grade,
        User $actor,
        string $event,
        ?string $reason,
        array $oldGrade,
        array $newGrade,
        CarbonImmutable $recordedAt,
    ): void {
        activity('grades')
            ->causedBy($actor)
            ->performedOn($grade)
            ->event($event)
            ->withProperties([
                'reason' => $reason,
                'old_grade' => $oldGrade,
                'new_grade' => $newGrade,
                'faculty_id' => $grade->faculty_id,
                'authorizer_id' => $actor->id,
                'enrollment_id' => $grade->enrollment_id,
                'enrollment_subject_id' => $grade->enrollment_subject_id,
                'subject_id' => $grade->subject_id,
            ])
            ->createdAt($recordedAt)
            ->log('Grade finalization state changed.');
    }
}
