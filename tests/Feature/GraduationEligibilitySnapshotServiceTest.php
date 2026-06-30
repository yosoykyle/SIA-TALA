<?php

namespace Tests\Feature;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\Hold;
use App\Models\ProgramShiftCreditEntry;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class GraduationEligibilitySnapshotServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function it_creates_immutable_complete_and_ready_for_review_versions_without_program_length_assumptions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member();
        $profile = $member->studentProfile;
        $first = $this->entry($profile, 'CAP-101', 1, 'First Year');
        $second = $this->entry($profile, 'CAP-202', 2, 'Second Year');
        $third = $this->entry($profile, 'CAP-303', 3, 'Third Year');

        foreach ([$first, $second, $third] as $entry) {
            $this->releasedGrade($profile, $entry, '2.00', GradeRosterRow::CategoryPassing);
        }

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);

        $this->assertSame(1, $snapshot->version);
        $this->assertSame(GraduationEligibilitySnapshotService::ResultComplete, $snapshot->result_status);
        $this->assertCount(3, $snapshot->evaluation_snapshot['completed_requirements']);
        $this->assertEquals(0.0, $snapshot->evaluation_snapshot['remaining_units']);
        $this->assertSame(['curriculum_entry', 'grade_roster_row'], collect($snapshot->evaluation_snapshot['source_references'])->pluck('type')->unique()->sort()->values()->all());

        $fourth = $this->entry($profile, 'CAP-404', 4, 'Fourth Year');
        $this->releasedGrade($profile, $fourth, '1.75', GradeRosterRow::CategoryPassing);

        $next = app(GraduationEligibilitySnapshotService::class)->generate($member->fresh(), $registrar);

        $this->assertSame(2, $next->version);
        $this->assertSame(GraduationEligibilitySnapshotService::ResultComplete, $next->result_status);
        $this->assertSame(2, $member->snapshots()->count());
        $this->assertCount(4, $next->evaluation_snapshot['completed_requirements']);
        $this->assertCount(3, $snapshot->fresh()->evaluation_snapshot['completed_requirements']);
    }

    #[Test]
    public function it_maps_all_blocker_categories_to_the_highest_priority_result(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member();
        $profile = $member->studentProfile;
        $missing = $this->entry($profile, 'MISS-101', 1);
        $failed = $this->entry($profile, 'FAIL-102', 2);
        $pending = $this->entry($profile, 'PEND-103', 3);
        $inc = $this->entry($profile, 'INC-104', 4);
        $withdrawn = $this->entry($profile, 'DROP-105', 5);
        $current = $this->entry($profile, 'CUR-106', 6);
        $this->releasedGrade($profile, $failed, '5.00', GradeRosterRow::CategoryFailed);
        $this->releasedGrade($profile, $pending, 'P', GradeRosterRow::CategoryPending);
        $this->releasedGrade($profile, $inc, 'INC', GradeRosterRow::CategoryIncomplete);
        $this->releasedGrade($profile, $withdrawn, 'W', GradeRosterRow::CategoryWithdrawn);
        $this->currentEnrollment($profile, $current);
        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeGraduationEligibility,
            'blocking_level' => Hold::BlockingGraduationEligibility,
            'status' => Hold::StatusActive,
            'reason' => 'Registrar clearance review is open.',
            'student_message' => 'Please contact the Registrar.',
        ]);

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);

        $this->assertSame(GraduationEligibilitySnapshotService::ResultBlockedHoldOrClearance, $snapshot->result_status);
        $this->assertSame([
            'current_enrollment_not_finalized',
            'failed_requirement',
            'hold_or_clearance',
            'inc_requirement',
            'missing_requirement',
            'pending_grade',
        ], collect($snapshot->evaluation_snapshot['blocker_groups'])->pluck('key')->sort()->values()->all());
        $this->assertSame([$missing->id], collect($snapshot->evaluation_snapshot['missing_requirements'])->pluck('curriculum_entry_id')->all());
        $this->assertSame([$failed->id], collect($snapshot->evaluation_snapshot['failed_requirements'])->pluck('curriculum_entry_id')->all());
        $this->assertSame([$pending->id], collect($snapshot->evaluation_snapshot['pending_grade_requirements'])->pluck('curriculum_entry_id')->all());
        $this->assertSame([$inc->id], collect($snapshot->evaluation_snapshot['inc_requirements'])->pluck('curriculum_entry_id')->all());
        $this->assertSame([$withdrawn->id], collect($snapshot->evaluation_snapshot['withdrawn_or_dropped_requirements'])->pluck('curriculum_entry_id')->all());
        $this->assertSame([$current->id], collect($snapshot->evaluation_snapshot['current_enrollments'])->pluck('curriculum_entry_id')->all());
    }

    #[Test]
    public function it_includes_credits_exceptions_and_clearance_blockers_with_source_references(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member();
        $profile = $member->studentProfile;
        $external = $this->entry($profile, 'TC-101', 1);
        $internal = $this->entry($profile, 'SHIFT-102', 2);
        $exception = $this->entry($profile, 'EXC-103', 3);
        $this->releasedGrade($profile, $external, 'TC', GradeRosterRow::CategoryTransferCredit);
        $shift = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $shift->id,
            'curriculum_entry_id' => $internal->id,
            'source_course_id' => $internal->courseSpecification->course_id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'numeric_grade' => '2.25',
        ]);
        EnrollmentException::factory()->create([
            'student_profile_id' => $profile->id,
            'exception_type' => EnrollmentException::TypePrerequisite,
            'original_rule' => 'GRADUATION:'.$exception->id,
            'scope_key' => 'completion-review',
            'state' => EnrollmentException::StateActive,
            'expires_at' => now()->addMonth(),
        ]);
        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeClearance,
            'blocking_level' => Hold::BlockingClearance,
            'status' => Hold::StatusActive,
            'reason' => 'Clearance office review.',
        ]);

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);

        $this->assertSame(GraduationEligibilitySnapshotService::ResultBlockedHoldOrClearance, $snapshot->result_status);
        $this->assertSame(['external_transfer_credit', 'internal_shift_credit'], collect($snapshot->evaluation_snapshot['accepted_credits'])->pluck('type')->sort()->values()->all());
        $this->assertSame([$exception->id], collect($snapshot->evaluation_snapshot['approved_exceptions'])->pluck('curriculum_entry_id')->all());
        $this->assertNotEmpty($snapshot->evaluation_snapshot['clearance_blockers']);
        $this->assertTrue(collect($snapshot->evaluation_snapshot['source_references'])->contains(fn (array $reference): bool => $reference['type'] === 'program_shift_credit_entry'));
        $this->assertTrue(collect($snapshot->evaluation_snapshot['source_references'])->contains(fn (array $reference): bool => $reference['type'] === 'enrollment_exception'));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function member(): GraduationReviewMember
    {
        $batch = GraduationReviewBatch::factory()->create();

        return GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => StudentProfile::factory()->create()->id,
        ])->load('studentProfile');
    }

    private function entry(StudentProfile $profile, string $code, int $sequence, string $year = 'First Year'): CurriculumEntry
    {
        $course = Course::factory()->create(['code' => $code]);
        $specification = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'title' => $code,
            'credit_units' => 3.00,
        ]);

        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $specification->id,
            'year_level' => $year,
            'sequence' => $sequence,
        ])->load('courseSpecification.course');
    }

    private function releasedGrade(StudentProfile $profile, CurriculumEntry $entry, string $code, string $category): GradeRosterRow
    {
        $courseEnrollment = $this->currentEnrollment($profile, $entry);
        $section = Section::factory()->create(['term_offering_id' => $courseEnrollment->term_offering_id]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $courseEnrollment->term_offering_id,
            'section_id' => $section->id,
            'faculty_user_id' => User::factory()->create(['status' => User::StatusActive])->id,
            'state' => GradeRoster::StateReleased,
            'released_at' => now(),
        ]);

        return GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $code,
            'current_outcome_category' => $category,
            'released_at' => now(),
        ]);
    }

    private function currentEnrollment(StudentProfile $profile, CurriculumEntry $entry): CourseEnrollment
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
        ]);

        return CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
        ]);
    }
}
