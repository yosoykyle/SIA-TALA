<?php

namespace App\Actions\Grades;

use App\Models\Grade;
use App\Models\GradeSubmissionPackage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class GradeSubmissionPackageService
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function submit(int $termId, int $sectionId, int $subjectId, User $faculty, ?CarbonImmutable $submittedAt = null): GradeSubmissionPackage
    {
        $timestamp = $submittedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($termId, $sectionId, $subjectId, $faculty, $timestamp): GradeSubmissionPackage {
            $this->assertFacultyCanSubmit($faculty);
            $this->assertFacultyAssigned($termId, $sectionId, $subjectId, $faculty);
            $this->assertNoActivePackage($termId, $sectionId, $subjectId, $faculty);

            $rows = $this->gradeRows($termId, $sectionId, $subjectId);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'section_id' => 'No active enrolled students were found for this section and subject.',
                ]);
            }

            $this->assertRowsComplete($rows);

            $package = GradeSubmissionPackage::query()->create([
                'term_id' => $termId,
                'section_id' => $sectionId,
                'subject_id' => $subjectId,
                'faculty_id' => $faculty->id,
                'state' => GradeSubmissionPackage::StateSubmitted,
                'roster_snapshot_checksum' => $this->checksum($rows),
                'grading_profile_snapshot' => $this->collegeProfileSnapshot(),
                'submitted_by' => $faculty->id,
                'submitted_at' => $timestamp,
            ]);

            foreach ($rows as $row) {
                $package->items()->create([
                    'enrollment_subject_id' => (int) $row->enrollment_subject_id,
                    'grade_id' => (int) $row->grade_id,
                    'enrollment_id' => (int) $row->enrollment_id,
                    'student_profile_id' => (int) $row->student_profile_id,
                    'subject_id' => (int) $row->subject_id,
                    'entered_values' => [
                        'prelim_grade' => $row->prelim_grade,
                        'midterm_grade' => $row->midterm_grade,
                        'final_grade' => $row->final_grade,
                    ],
                    'derived_grade' => [
                        'grade' => $row->grade,
                        'remarks' => $row->remarks,
                        'is_inc' => (bool) $row->is_inc,
                        'inc_expires_at' => $row->inc_expires_at,
                    ],
                    'remarks' => $row->remarks,
                ]);
            }

            $this->recordPackageAudit($package, $faculty, 'grade_package_submitted', null, $timestamp);

            return $package->fresh(['items']);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function returnForRevision(GradeSubmissionPackage $package, User $registrar, string $reason, ?CarbonImmutable $returnedAt = null): GradeSubmissionPackage
    {
        $timestamp = $returnedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($package, $registrar, $reason, $timestamp): GradeSubmissionPackage {
            $lockedPackage = $this->lockedPackage($package);

            $this->assertRegistrarCanVerify($registrar);
            $this->assertState($lockedPackage, GradeSubmissionPackage::StateSubmitted, 'Only submitted grade packages can be returned.');
            $this->assertText($reason, 'return_reason', 500);

            $lockedPackage->forceFill([
                'state' => GradeSubmissionPackage::StateReturned,
                'registrar_reviewed_by' => $registrar->id,
                'registrar_reviewed_at' => $timestamp,
                'return_reason' => trim($reason),
            ])->save();

            $lockedPackage->refresh();
            $this->recordPackageAudit($lockedPackage, $registrar, 'grade_package_returned', $reason, $timestamp);

            return $lockedPackage;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function verifyAndFinalize(GradeSubmissionPackage $package, User $registrar, ?CarbonImmutable $verifiedAt = null): GradeSubmissionPackage
    {
        $timestamp = $verifiedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($package, $registrar, $timestamp): GradeSubmissionPackage {
            $lockedPackage = $this->lockedPackage($package);

            $this->assertRegistrarCanVerify($registrar);
            $this->assertState($lockedPackage, GradeSubmissionPackage::StateSubmitted, 'Only submitted grade packages can be verified and finalized.');

            $items = $lockedPackage->items()->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Submitted grade package has no grade items.',
                ]);
            }

            $grades = Grade::query()
                ->whereIn('id', $items->pluck('grade_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $grade = $grades->get($item->grade_id);

                if (! $grade instanceof Grade) {
                    throw ValidationException::withMessages([
                        'grade_id' => 'A submitted grade item no longer points to an existing grade.',
                    ]);
                }

                $this->assertGradeMatchesSnapshot($grade, $item->entered_values ?? [], $item->derived_grade ?? []);

                if ($grade->is_finalized) {
                    continue;
                }

                $grade->forceFill([
                    'is_finalized' => true,
                    'finalized_by' => $registrar->id,
                    'finalized_at' => $timestamp,
                ])->save();
            }

            $lockedPackage->forceFill([
                'state' => GradeSubmissionPackage::StateVerifiedFinalized,
                'registrar_reviewed_by' => $registrar->id,
                'registrar_reviewed_at' => $timestamp,
                'finalized_at' => $timestamp,
            ])->save();

            $lockedPackage->refresh();
            $this->recordPackageAudit($lockedPackage, $registrar, 'grade_package_verified_finalized', null, $timestamp);

            return $lockedPackage->load('items');
        });
    }

    private function lockedPackage(GradeSubmissionPackage $package): GradeSubmissionPackage
    {
        return GradeSubmissionPackage::query()
            ->whereKey($package->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @throws AuthorizationException
     */
    private function assertFacultyCanSubmit(User $faculty): void
    {
        if ($faculty->hasRole('faculty') && $faculty->can('finalize-grades')) {
            return;
        }

        throw new AuthorizationException('Only assigned faculty with grade submission permission can submit grade packages.');
    }

    /**
     * @throws AuthorizationException
     */
    private function assertRegistrarCanVerify(User $registrar): void
    {
        if ($registrar->hasRole('registrar') && $registrar->can('verify-grade-submissions')) {
            return;
        }

        throw new AuthorizationException('Only Registrar staff can verify or return grade submissions.');
    }

    /**
     * @throws AuthorizationException
     */
    private function assertFacultyAssigned(int $termId, int $sectionId, int $subjectId, User $faculty): void
    {
        $assigned = DB::table('section_teacher')
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->where('user_id', $faculty->id)
            ->exists()
            || DB::table('section_meetings')
                ->where('term_id', $termId)
                ->where('section_id', $sectionId)
                ->where('subject_id', $subjectId)
                ->where('faculty_id', $faculty->id)
                ->exists();

        if ($assigned) {
            return;
        }

        throw new AuthorizationException('Only the assigned faculty can submit this grade package.');
    }

    /**
     * @throws ValidationException
     */
    private function assertNoActivePackage(int $termId, int $sectionId, int $subjectId, User $faculty): void
    {
        $exists = GradeSubmissionPackage::query()
            ->where('term_id', $termId)
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->where('faculty_id', $faculty->id)
            ->whereIn('state', [
                GradeSubmissionPackage::StateSubmitted,
                GradeSubmissionPackage::StateVerifiedFinalized,
            ])
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'grade_submission_package' => 'An active grade submission package already exists for this class and subject.',
        ]);
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function gradeRows(int $termId, int $sectionId, int $subjectId): Collection
    {
        return DB::table('enrollment_subjects')
            ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
            ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_profile_id')
            ->leftJoin('grades', function ($join): void {
                $join
                    ->on('grades.enrollment_subject_id', '=', 'enrollment_subjects.id')
                    ->on('grades.subject_id', '=', 'enrollment_subjects.subject_id');
            })
            ->where('enrollments.term_id', $termId)
            ->where('enrollments.section_id', $sectionId)
            ->where('enrollment_subjects.subject_id', $subjectId)
            ->where('enrollment_subjects.status', 'enrolled')
            ->where('enrollment_subjects.is_dropped', false)
            ->orderBy('student_profiles.student_id')
            ->select([
                'enrollment_subjects.id as enrollment_subject_id',
                'enrollment_subjects.enrollment_id',
                'enrollment_subjects.subject_id',
                'student_profiles.id as student_profile_id',
                'grades.id as grade_id',
                'grades.prelim_grade',
                'grades.midterm_grade',
                'grades.final_grade',
                'grades.grade',
                'grades.remarks',
                'grades.is_inc',
                'grades.inc_expires_at',
                'grades.is_finalized',
            ])
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     *
     * @throws ValidationException
     */
    private function assertRowsComplete(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($row->grade_id === null) {
                throw ValidationException::withMessages([
                    'grades' => 'All enrolled students in the package must have an encoded grade or INC before submission.',
                ]);
            }

            if ((bool) $row->is_finalized) {
                throw ValidationException::withMessages([
                    'grades' => 'Finalized grades cannot be resubmitted as a new package.',
                ]);
            }

            if ((bool) $row->is_inc) {
                if ($row->remarks === 'inc' && $row->inc_expires_at !== null) {
                    continue;
                }

                throw ValidationException::withMessages([
                    'grades' => 'INC rows require INC remarks and an expiry date before submission.',
                ]);
            }

            foreach (['prelim_grade', 'midterm_grade', 'final_grade', 'grade', 'remarks'] as $field) {
                if ($row->{$field} === null || $row->{$field} === '') {
                    throw ValidationException::withMessages([
                        'grades' => 'All grade rows must be complete before submission.',
                    ]);
                }
            }
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    private function checksum(Collection $rows): string
    {
        $payload = $rows
            ->map(fn (stdClass $row): array => [
                'enrollment_subject_id' => (int) $row->enrollment_subject_id,
                'grade_id' => (int) $row->grade_id,
                'prelim_grade' => $row->prelim_grade,
                'midterm_grade' => $row->midterm_grade,
                'final_grade' => $row->final_grade,
                'grade' => $row->grade,
                'remarks' => $row->remarks,
                'is_inc' => (bool) $row->is_inc,
                'inc_expires_at' => $row->inc_expires_at,
            ])
            ->values()
            ->all();

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function collegeProfileSnapshot(): array
    {
        return [
            'scheme' => 'college',
            'periods' => ['prelim', 'midterm', 'final'],
            'calculation' => '30/30/40 weighted raw average, then College transmutation',
        ];
    }

    /**
     * @param  array<string, mixed>  $enteredValues
     * @param  array<string, mixed>  $derivedGrade
     *
     * @throws ValidationException
     */
    private function assertGradeMatchesSnapshot(Grade $grade, array $enteredValues, array $derivedGrade): void
    {
        $expected = [
            'prelim_grade' => $enteredValues['prelim_grade'] ?? null,
            'midterm_grade' => $enteredValues['midterm_grade'] ?? null,
            'final_grade' => $enteredValues['final_grade'] ?? null,
            'grade' => $derivedGrade['grade'] ?? null,
            'remarks' => $derivedGrade['remarks'] ?? null,
            'is_inc' => (bool) ($derivedGrade['is_inc'] ?? false),
            'inc_expires_at' => $derivedGrade['inc_expires_at'] ?? null,
        ];

        foreach ($expected as $field => $value) {
            $actual = $grade->{$field};

            if ($actual instanceof \DateTimeInterface) {
                $actual = $actual->format('Y-m-d H:i:s');
            }

            if ($this->snapshotValue($field, $actual) !== $this->snapshotValue($field, $value)) {
                throw ValidationException::withMessages([
                    'grade_snapshot' => 'Submitted grade values changed after package submission.',
                ]);
            }
        }
    }

    private function snapshotValue(string $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (in_array($field, ['prelim_grade', 'midterm_grade', 'final_grade', 'grade'], true) && is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        if ($field === 'is_inc') {
            return (bool) $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @throws ValidationException
     */
    private function assertState(GradeSubmissionPackage $package, string $state, string $message): void
    {
        if ($package->state === $state) {
            return;
        }

        throw ValidationException::withMessages([
            'state' => $message,
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

    private function recordPackageAudit(
        GradeSubmissionPackage $package,
        User $actor,
        string $event,
        ?string $reason,
        CarbonImmutable $recordedAt,
    ): void {
        activity('grade_submission_packages')
            ->causedBy($actor)
            ->performedOn($package)
            ->event($event)
            ->withProperties([
                'state' => $package->state,
                'reason' => $reason,
                'term_id' => $package->term_id,
                'section_id' => $package->section_id,
                'subject_id' => $package->subject_id,
                'faculty_id' => $package->faculty_id,
            ])
            ->createdAt($recordedAt)
            ->log('Grade submission package state changed.');
    }
}
