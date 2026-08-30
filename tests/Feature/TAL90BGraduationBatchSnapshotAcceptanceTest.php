<?php

namespace Tests\Feature;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-90B: Graduation Batch and Snapshot Acceptance.
 *
 * Owning contract: PRD 11_student_lifecycle.md Section 11.3.1 (Graduation Eligibility),
 * as clarified for finalized vs non-finalized current enrollment (rules 5-6) and
 * withdrawn/dropped requirements contributing to Blocked: Missing Requirement (rule 7).
 */
final class TAL90BGraduationBatchSnapshotAcceptanceTest extends TestCase
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
    public function ready_for_registrar_review_when_only_remainder_is_an_officially_finalized_current_enrollment(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member();
        $profile = $member->studentProfile;

        $done = $this->entry($profile, 'DONE-101', 1);
        $this->releasedGrade($profile, $done, '2.00', GradeRosterRow::CategoryPassing);

        $inProgress = $this->entry($profile, 'NOW-201', 2);
        $this->currentEnrollment($profile, $inProgress, finalized: true);

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);

        $this->assertSame(
            GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview,
            $snapshot->result_status,
        );
        $this->assertSame([], $snapshot->evaluation_snapshot['blocker_groups']);
        $this->assertSame(
            [$inProgress->id],
            collect($snapshot->evaluation_snapshot['current_enrollments'])->pluck('curriculum_entry_id')->all(),
        );
        $this->assertTrue($snapshot->evaluation_snapshot['current_enrollments'][0]['finalized']);
        $this->assertContains(
            'NOW-201 NOW-201',
            $snapshot->evaluation_snapshot['student_projection']['in_progress_requirements'],
        );
    }

    #[Test]
    public function blocked_current_enrollment_not_finalized_when_the_current_enrollment_is_not_official(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member();
        $profile = $member->studentProfile;

        $done = $this->entry($profile, 'DONE-101', 1);
        $this->releasedGrade($profile, $done, '2.00', GradeRosterRow::CategoryPassing);

        $pendingEnrollment = $this->entry($profile, 'PEND-201', 2);
        $this->currentEnrollment($profile, $pendingEnrollment, finalized: false);

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);

        $this->assertSame(
            GraduationEligibilitySnapshotService::ResultBlockedCurrentEnrollmentNotFinalized,
            $snapshot->result_status,
        );
        $this->assertSame(
            ['current_enrollment_not_finalized'],
            collect($snapshot->evaluation_snapshot['blocker_groups'])->pluck('key')->all(),
        );
        $this->assertFalse($snapshot->evaluation_snapshot['current_enrollments'][0]['finalized']);
    }

    #[Test]
    public function withdrawn_required_course_counts_toward_missing_blocker_and_stays_itemized(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member();
        $profile = $member->studentProfile;

        $done = $this->entry($profile, 'DONE-101', 1);
        $this->releasedGrade($profile, $done, '2.00', GradeRosterRow::CategoryPassing);

        $withdrawn = $this->entry($profile, 'WD-201', 2);
        $this->releasedGrade($profile, $withdrawn, 'W', GradeRosterRow::CategoryWithdrawn);

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);

        $this->assertSame(
            GraduationEligibilitySnapshotService::ResultBlockedMissingRequirement,
            $snapshot->result_status,
        );
        $missingGroup = collect($snapshot->evaluation_snapshot['blocker_groups'])->firstWhere('key', 'missing_requirement');
        $this->assertNotNull($missingGroup);
        $this->assertSame(1, $missingGroup['count']);
        // Still itemized under its own output field (PRD field 9).
        $this->assertSame(
            [$withdrawn->id],
            collect($snapshot->evaluation_snapshot['withdrawn_or_dropped_requirements'])->pluck('curriculum_entry_id')->all(),
        );
        $this->assertContains(
            'WD-201 WD-201',
            $snapshot->evaluation_snapshot['student_projection']['remaining_requirements'],
        );
    }

    #[Test]
    public function irregular_student_reaches_ready_for_review_against_the_same_curriculum(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member(StudentProfile::StandingIrregular);
        $profile = $member->studentProfile;
        $this->assertSame(StudentProfile::StandingIrregular, $profile->academic_standing);

        // Requirements satisfied out of the standard sequence, with one finalized current enrollment.
        $first = $this->entry($profile, 'IRR-303', 3);
        $second = $this->entry($profile, 'IRR-101', 1);
        $this->releasedGrade($profile, $first, '1.75', GradeRosterRow::CategoryPassing);
        $this->releasedGrade($profile, $second, '2.50', GradeRosterRow::CategoryPassing);
        $current = $this->entry($profile, 'IRR-202', 2);
        $this->currentEnrollment($profile, $current, finalized: true);

        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);

        $this->assertSame(
            GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview,
            $snapshot->result_status,
        );
        $this->assertSame([], $snapshot->evaluation_snapshot['blocker_groups']);
    }

    #[Test]
    public function refresh_after_resolving_a_source_record_creates_a_new_version_and_keeps_the_prior_immutable(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $member = $this->member();
        $profile = $member->studentProfile;

        $entry = $this->entry($profile, 'RES-101', 1);
        $pendingRow = $this->releasedGrade($profile, $entry, null, null, released: false);

        $first = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);
        $this->assertSame(1, $first->version);
        $this->assertSame(
            GraduationEligibilitySnapshotService::ResultBlockedPendingGrade,
            $first->result_status,
        );

        // Registrar resolves the source record: the pending grade becomes a released passing grade.
        $pendingRow->update([
            'current_outcome_code' => '2.00',
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'released_at' => now(),
        ]);

        $second = app(GraduationEligibilitySnapshotService::class)->generate($member->fresh(), $registrar);

        $this->assertSame(2, $second->version);
        $this->assertSame(GraduationEligibilitySnapshotService::ResultComplete, $second->result_status);
        // Prior version is immutable.
        $this->assertSame(
            GraduationEligibilitySnapshotService::ResultBlockedPendingGrade,
            $first->fresh()->result_status,
        );
        $this->assertSame(2, $member->snapshots()->count());
    }

    #[Test]
    public function batch_membership_records_metadata_and_rejects_duplicate_members(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $batch = GraduationReviewBatch::factory()->create(['created_by' => $registrar->id]);
        $profile = StudentProfile::factory()->create();

        $member = GraduationReviewMember::query()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => $profile->id,
            'added_by' => $registrar->id,
            'added_at' => now(),
            'is_active' => true,
        ]);

        $this->assertSame($registrar->id, $member->added_by);
        $this->assertTrue($member->is_active);
        $this->assertNotNull($member->added_at);

        // Membership is a unique review-list entry per (batch, student).
        $this->expectException(QueryException::class);
        GraduationReviewMember::query()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => $profile->id,
            'added_by' => $registrar->id,
            'added_at' => now(),
            'is_active' => true,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function member(?string $standing = null): GraduationReviewMember
    {
        $batch = GraduationReviewBatch::factory()->create();
        $profile = StudentProfile::factory()->create(
            $standing === null ? [] : ['academic_standing' => $standing],
        );

        return GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => $profile->id,
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

    private function releasedGrade(StudentProfile $profile, CurriculumEntry $entry, ?string $code, ?string $category, bool $released = true): GradeRosterRow
    {
        $courseEnrollment = $this->currentEnrollment($profile, $entry, finalized: true);
        $section = Section::factory()->create(['term_offering_id' => $courseEnrollment->term_offering_id]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $courseEnrollment->term_offering_id,
            'section_id' => $section->id,
            'faculty_user_id' => User::factory()->create(['status' => User::StatusActive])->id,
            'state' => $released ? GradeRoster::StateReleased : GradeRoster::StateSubmitted,
            'released_at' => $released ? now() : null,
        ]);

        return GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $code,
            'current_outcome_category' => $category,
            'released_at' => $released ? now() : null,
        ]);
    }

    private function currentEnrollment(StudentProfile $profile, CurriculumEntry $entry, bool $finalized): CourseEnrollment
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
            'status' => $finalized ? 'officially_enrolled' : 'pending_payment',
            'officially_enrolled_at' => $finalized ? now() : null,
        ]);

        return CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
        ]);
    }
}
