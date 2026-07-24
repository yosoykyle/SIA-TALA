<?php

namespace Database\Seeders;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Models\AcademicYear;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\Hold;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Adds deterministic TAL-96D4B grade and lifecycle states to an existing acceptance baseline.
 *
 * This opt-in overlay is never called by DatabaseSeeder and does not create or replace a
 * MIN, MIDDLE, or MAX scheduling scenario.
 */
final class TAL96D4BAcceptanceStateSeeder extends Seeder
{
    public const BatchName = 'TAL-96D4B Completion Acceptance';

    public function run(): void
    {
        $term = Term::query()
            ->with('academicYear')
            ->whereHas('termOfferings', fn ($query) => $query->whereHas('sections'), '>=', 4)
            ->withCount('termOfferings')
            ->orderByDesc('term_offerings_count')
            ->orderByDesc('id')
            ->first();
        $profiles = StudentProfile::query()->orderBy('student_number')->limit(4)->get();
        $offerings = TermOffering::query()
            ->where('term_id', $term?->id)
            ->whereHas('sections')
            ->with('sections')
            ->orderBy('id')
            ->limit(4)
            ->get();
        $faculty = User::role(User::StaffRoleFaculty)->orderBy('id')->first();
        $registrar = User::role(User::StaffRoleRegistrar)->orderBy('id')->first();

        if (! $term instanceof Term || $profiles->count() < 4
            || $offerings->count() < 4
            || ! $faculty instanceof User || ! $registrar instanceof User) {
            throw new RuntimeException('TAL-96D4B acceptance states require an existing acceptance baseline with one term, four students, four offerings, four sections, Faculty, and Registrar users.');
        }

        $states = [
            GradeRoster::StateDraft,
            GradeRoster::StateSubmitted,
            GradeRoster::StateReturned,
            GradeRoster::StateReleased,
        ];

        foreach ($states as $index => $state) {
            /** @var StudentProfile $profile */
            $profile = $profiles[$index];
            /** @var TermOffering $offering */
            $offering = $offerings[$index];
            $section = $offering->sections->sortBy('id')->first();

            if ($section === null) {
                throw new RuntimeException('Every TAL-96D4B grade-roster example requires a section owned by its selected offering.');
            }
            $enrollment = Enrollment::query()->firstOrCreate(
                ['student_profile_id' => $profile->id, 'term_id' => $term->id],
                ['status' => 'officially_enrolled', 'student_type' => 'regular', 'officially_enrolled_at' => now()],
            );
            $courseEnrollment = CourseEnrollment::query()->firstOrCreate(
                ['enrollment_id' => $enrollment->id, 'term_offering_id' => $offering->id],
                ['status' => CourseEnrollment::StatusActive, 'units_snapshot' => '3.00', 'added_at' => now()],
            );
            $roster = GradeRoster::query()->firstOrCreate(
                ['term_offering_id' => $offering->id, 'section_id' => $section->id, 'faculty_user_id' => $faculty->id],
                ['state' => $state, 'grading_profile_snapshot' => config('grades.servitech_v1')],
            );
            $roster->forceFill([
                'state' => $state,
                'submitted_by' => $state === GradeRoster::StateDraft ? null : $faculty->id,
                'submitted_at' => $state === GradeRoster::StateDraft ? null : now()->subHours(2),
                'reviewed_by' => in_array($state, [GradeRoster::StateReturned, GradeRoster::StateReleased], true) ? $registrar->id : null,
                'reviewed_at' => in_array($state, [GradeRoster::StateReturned, GradeRoster::StateReleased], true) ? now()->subHour() : null,
                'released_by' => $state === GradeRoster::StateReleased ? $registrar->id : null,
                'released_at' => $state === GradeRoster::StateReleased ? now() : null,
                'return_reason' => $state === GradeRoster::StateReturned ? 'Confirm the final equivalent before resubmission.' : null,
            ])->save();

            GradeRosterRow::query()->updateOrCreate(
                ['grade_roster_id' => $roster->id, 'course_enrollment_id' => $courseEnrollment->id],
                [
                    'prelim_equivalent' => $state === GradeRoster::StateDraft ? null : 88,
                    'midterm_equivalent' => $state === GradeRoster::StateDraft ? null : 90,
                    'final_equivalent' => $state === GradeRoster::StateDraft ? null : 91,
                    'computed_average' => $state === GradeRoster::StateDraft ? null : 89.8,
                    'current_outcome_code' => $state === GradeRoster::StateReleased ? '1.75' : null,
                    'current_outcome_category' => $state === GradeRoster::StateReleased ? GradeRosterRow::CategoryPassing : null,
                    'released_at' => $state === GradeRoster::StateReleased ? now() : null,
                ],
            );
        }

        $this->seedHolds($profiles[3], $profiles[0], $term, $registrar);
        $this->seedLifecycleChanges($profiles[1], $profiles[2], $term, $registrar);
        $this->seedGraduationReviews($profiles[0], $profiles[3], $term, $registrar);
    }

    private function seedHolds(StudentProfile $activeProfile, StudentProfile $resolvedProfile, Term $term, User $registrar): void
    {
        Hold::query()->updateOrCreate(
            ['student_profile_id' => $activeProfile->id, 'source_type' => self::class, 'source_id' => 1],
            [
                'term_id' => $term->id,
                'hold_type' => Hold::TypeFinancial,
                'blocking_level' => Hold::BlockingGraduationEligibility,
                'status' => Hold::StatusActive,
                'reason' => 'Acceptance fixture: outstanding assessed balance.',
                'student_message' => 'Please coordinate with the Accounting Office to settle or arrange the assessed balance.',
                'created_by' => $registrar->id,
                'effective_at' => now(),
                'resolution_requirement' => 'Accounting must record settlement or an approved clearance before completion can be confirmed.',
            ],
        );
        Hold::query()->updateOrCreate(
            ['student_profile_id' => $resolvedProfile->id, 'source_type' => self::class, 'source_id' => 2],
            [
                'term_id' => $term->id,
                'hold_type' => Hold::TypeDocumentary,
                'blocking_level' => Hold::BlockingRecordRelease,
                'status' => Hold::StatusResolved,
                'reason' => 'Acceptance fixture: documentary requirement completed.',
                'student_message' => 'Your documentary requirement has been cleared.',
                'created_by' => $registrar->id,
                'effective_at' => now()->subDays(2),
                'resolved_by' => $registrar->id,
                'resolved_at' => now()->subDay(),
                'resolution_requirement' => 'Completed.',
            ],
        );
    }

    private function seedLifecycleChanges(StudentProfile $withdrawn, StudentProfile $shift, Term $term, User $registrar): void
    {
        StudentLifecycleChange::query()->updateOrCreate(
            ['private_source_reference' => 'TAL-96D4B-WITHDRAWAL'],
            [
                'student_profile_id' => $withdrawn->id,
                'term_id' => $term->id,
                'type' => StudentLifecycleChange::TypeWithdrawal,
                'requested_on' => today(),
                'effective_on' => today(),
                'decided_on' => today(),
                'authority' => 'Registrar acceptance fixture',
                'reason' => 'Representative approved withdrawal for cross-role acceptance.',
                'impact_snapshot' => $this->impactSnapshot('withdrawn', false, 0),
                'recorded_by' => $registrar->id,
                'state' => StudentLifecycleChange::StateApplied,
            ],
        );
        $targetCurriculum = CurriculumVersion::query()
            ->where('program_id', '!=', $shift->program_id)
            ->whereHas('entries')
            ->with('entries')
            ->orderBy('id')
            ->first();

        if (! $targetCurriculum instanceof CurriculumVersion) {
            throw new RuntimeException('The TAL-96D4B Program Shift example requires a different target program with at least one curriculum entry.');
        }

        $futureTerm = $this->futureProgramShiftTerm($term);
        $programShift = StudentLifecycleChange::query()->updateOrCreate(
            ['private_source_reference' => 'TAL-96D4B-PROGRAM-SHIFT'],
            [
                'student_profile_id' => $shift->id,
                'term_id' => $futureTerm->id,
                'target_program_id' => $targetCurriculum->program_id,
                'target_curriculum_version_id' => $targetCurriculum->id,
                'type' => StudentLifecycleChange::TypeProgramShift,
                'requested_on' => today(),
                'effective_on' => $futureTerm->starts_on,
                'decided_on' => today(),
                'authority' => 'Registrar acceptance fixture',
                'reason' => 'Representative recorded-approved program-shift review.',
                'impact_snapshot' => $this->impactSnapshot($shift->lifecycle_status, true, 500),
                'recorded_by' => $registrar->id,
                'state' => StudentLifecycleChange::StateRecordedApproved,
            ],
        );

        $targetEntry = $targetCurriculum->entries->sortBy('id')->first();

        if (! $targetEntry instanceof CurriculumEntry) {
            throw new RuntimeException('The TAL-96D4B Program Shift target curriculum requires at least one credit-checklist row.');
        }

        ProgramShiftCreditEntry::query()->updateOrCreate(
            [
                'student_lifecycle_change_id' => $programShift->id,
                'curriculum_entry_id' => $targetEntry->id,
            ],
            [
                'treatment' => ProgramShiftCreditEntry::TreatmentDeficient,
                'state' => ProgramShiftCreditEntry::StateRecorded,
            ],
        );
    }

    private function futureProgramShiftTerm(Term $sourceTerm): Term
    {
        $startsOn = today()->addYear()->startOfMonth();
        $endsOn = $startsOn->copy()->addMonths(4)->endOfMonth();
        $academicYear = AcademicYear::query()->updateOrCreate(
            ['label' => 'TAL-96D4B Acceptance Future Academic Year'],
            [
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'state' => AcademicYear::StateDraft,
            ],
        );

        return Term::query()->updateOrCreate(
            [
                'academic_year_id' => $academicYear->id,
                'type' => Term::TypeFirstSemester,
                'label' => 'TAL-96D4B Future Program Shift Term',
            ],
            [
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'state' => Term::StateDraft,
                'scheduling_slot_minutes' => $sourceTerm->scheduling_slot_minutes,
                'scheduling_days' => $sourceTerm->scheduling_days,
                'scheduling_day_starts_at' => $sourceTerm->scheduling_day_starts_at,
                'scheduling_day_ends_at' => $sourceTerm->scheduling_day_ends_at,
                'default_max_units' => $sourceTerm->default_max_units,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function impactSnapshot(string $profileStatus, bool $corAvailable, float $financeAdjustment): array
    {
        return [
            'course_enrollment_ids' => [],
            'binding_ids' => [],
            'reservation_ids' => [],
            'master_schedule_changes' => 0,
            'profile_status_after' => $profileStatus,
            'finance_adjustment' => $financeAdjustment,
            'cor_available_after' => $corAvailable,
        ];
    }

    private function seedGraduationReviews(StudentProfile $complete, StudentProfile $blocked, Term $term, User $registrar): void
    {
        $batch = GraduationReviewBatch::query()->firstOrCreate(
            ['name' => self::BatchName],
            [
                'academic_year_id' => $term->academic_year_id,
                'term_id' => $term->id,
                'state' => GraduationReviewBatch::StateOpen,
                'created_by' => $registrar->id,
                'filter_summary' => ['purpose' => 'TAL-96D4B cross-role acceptance'],
            ],
        );

        foreach ([
            [$complete, GraduationEligibilitySnapshotService::ResultComplete, [], 'No further action is required.'],
            [$blocked, GraduationEligibilitySnapshotService::ResultBlockedHoldOrClearance, ['Clear the active hold.'], 'Contact the responsible office shown in the hold notice.'],
        ] as [$profile, $status, $requirements, $action]) {
            $member = GraduationReviewMember::query()->firstOrCreate(
                ['graduation_review_batch_id' => $batch->id, 'student_profile_id' => $profile->id],
                ['added_by' => $registrar->id, 'added_at' => now(), 'is_active' => true],
            );
            GraduationSnapshot::query()->updateOrCreate(
                ['graduation_review_member_id' => $member->id, 'version' => 1],
                [
                    'result_status' => $status,
                    'evaluation_snapshot' => [
                        'student_projection' => [
                            'result_status' => $status,
                            'remaining_requirements' => $requirements,
                            'pending_grade_blockers' => [],
                            'inc_blockers' => [],
                            'hold_or_clearance_labels' => $requirements,
                            'required_action' => $action,
                            'office_to_contact' => $status === GraduationEligibilitySnapshotService::ResultComplete ? 'Registrar Office' : 'Accounting Office',
                        ],
                    ],
                    'generated_by' => $registrar->id,
                    'generated_at' => now(),
                    'made_visible_by' => $registrar->id,
                    'made_visible_at' => now(),
                    'visibility_reason' => 'TAL-96D4B acceptance projection.',
                ],
            );
        }
    }
}
