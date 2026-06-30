<?php

namespace Tests\Feature;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
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

final class AcademicProgressionServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function it_evaluates_grouped_prerequisites_ordered_minimums_transfer_credit_and_scoped_exceptions(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $alternativeA = $this->entry($profile, 'PRE-A', 1, 'First Year');
        $alternativeB = $this->entry($profile, 'PRE-B', 2, 'First Year');
        $requiredB = $this->entry($profile, 'PRE-C', 3, 'Second Year');
        $target = $this->entry($profile, 'TARGET', 4, 'Third Year');
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $target->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $this->requirement($target, $alternativeA, 'A', '2.50');
        $this->requirement($target, $alternativeB, 'A', null, acceptsTransfer: true);
        $this->requirement($target, $requiredB, 'B');
        $this->grade($profile, $alternativeA, '3.00', GradeRosterRow::CategoryPassing);
        $this->grade($profile, $alternativeB, 'TC', GradeRosterRow::CategoryTransferCredit);
        $this->grade($profile, $requiredB, 'INC', GradeRosterRow::CategoryIncomplete);

        $result = app(AcademicProgressionService::class)->evaluate($profile, $term);
        $this->assertSame(StudentProfile::StandingBlockedByPrerequisite, $result['standing']);
        $this->assertSame('3.00', $result['gwa']);
        $this->assertCount(2, $result['blockers']);
        $this->assertSame('PREREQUISITE:B', collect($result['blockers'])->firstWhere('kind', 'prerequisite')['rule']);

        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id, 'term_id' => $term->id]);
        EnrollmentException::factory()->create([
            'enrollment_id' => $enrollment->id,
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'exception_type' => EnrollmentException::TypePrerequisite,
            'target_term_offering_id' => $offering->id,
            'original_rule' => 'PREREQUISITE:B',
            'scope_key' => 'target-b',
            'expires_at' => now()->addDay(),
        ]);

        $result = app(AcademicProgressionService::class)->evaluate($profile, $term);
        $this->assertNotNull(collect($result['suggestions'])->firstWhere('course_code', 'TARGET'));
        $this->assertSame(3, $result['curriculum_length']);

        EnrollmentException::query()->where('scope_key', 'target-b')->update(['expires_at' => now()->subSecond()]);
        $this->assertNull(collect(app(AcademicProgressionService::class)->evaluate($profile, $term)['suggestions'])->firstWhere('course_code', 'TARGET'));
    }

    #[Test]
    public function current_enrollment_satisfies_only_corequisites_and_internal_numeric_shift_credit_can_satisfy_prerequisites(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $credited = $this->entry($profile, 'CREDITED', 1);
        $corequisite = $this->entry($profile, 'COREQ', 2);
        $target = $this->entry($profile, 'TARGET-2', 3);
        $targetOffering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $target->id, 'state' => TermOffering::StateScheduled]);
        $corequisiteOffering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $corequisite->id, 'state' => TermOffering::StateScheduled]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $target->course_specification_id,
            'related_course_id' => $credited->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'PREREQ',
            'minimum_grade' => '3.00',
            'state' => CourseRequirement::StateActive,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $target->course_specification_id,
            'related_course_id' => $corequisite->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypeCorequisite,
            'group_key' => 'COREQ',
            'state' => CourseRequirement::StateActive,
        ]);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id, 'term_id' => $term->id]);
        CourseEnrollment::query()->create(['enrollment_id' => $enrollment->id, 'term_offering_id' => $corequisiteOffering->id, 'status' => CourseEnrollment::StatusActive]);
        $shift = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $shift->id,
            'curriculum_entry_id' => $credited->id,
            'source_course_id' => $credited->courseSpecification->course_id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'numeric_grade' => '2.50',
        ]);

        $result = app(AcademicProgressionService::class)->evaluate($profile, $term);
        $this->assertSame($targetOffering->id, collect($result['suggestions'])->firstWhere('course_code', 'TARGET-2')['term_offering_id']);
        $this->assertContains($corequisite->courseSpecification->course_id, $result['current_course_ids']);
        $this->assertSame('2.50', $result['gwa']);
    }

    #[Test]
    public function it_separates_back_subjects_from_active_pending_and_incomplete_blockers(): void
    {
        $profile = StudentProfile::factory()->create();
        $failed = $this->entry($profile, 'FAILED', 1);
        $pending = $this->entry($profile, 'PENDING', 2);
        $incomplete = $this->entry($profile, 'INCOMPLETE', 3);
        $withdrawn = $this->entry($profile, 'WITHDRAWN', 4);
        $this->grade($profile, $failed, '5.00', GradeRosterRow::CategoryFailed);
        $this->grade($profile, $pending, 'P', GradeRosterRow::CategoryPending);
        $this->grade($profile, $incomplete, 'INC', GradeRosterRow::CategoryIncomplete);
        $this->grade($profile, $withdrawn, 'W', GradeRosterRow::CategoryWithdrawn);

        $result = app(AcademicProgressionService::class)->evaluate($profile);
        $codes = collect($result['back_subjects'])->pluck('course_code')->all();
        $this->assertContains('FAILED', $codes);
        $this->assertContains('WITHDRAWN', $codes);
        $this->assertNotContains('PENDING', $codes);
        $this->assertNotContains('INCOMPLETE', $codes);
        $this->assertSame(['active_inc', 'pending_grade'], collect($result['blockers'])->pluck('reason')->sort()->values()->all());
        $this->assertSame(StudentProfile::StandingDeficient, $result['standing']);
    }

    #[Test]
    public function registrar_confirmation_is_audited_and_does_not_happen_during_evaluation(): void
    {
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingRegular]);
        $this->entry($profile, 'COURSE', 1);
        app(AcademicProgressionService::class)->evaluate($profile);
        $this->assertSame(StudentProfile::StandingRegular, $profile->fresh()->academic_standing);

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        app(AcademicProgressionService::class)->confirmStanding($profile, StudentProfile::StandingCompletionCandidate, $registrar, 'Completion facts reviewed.');

        $this->assertSame(StudentProfile::StandingCompletionCandidate, $profile->fresh()->academic_standing);
        $this->assertDatabaseHas('activity_log', ['event' => 'academic_standing_confirmed', 'subject_id' => $profile->id]);
    }

    private function entry(StudentProfile $profile, string $code, int $sequence, string $year = 'First Year'): CurriculumEntry
    {
        $course = Course::factory()->create(['code' => $code]);
        $specification = CourseSpecification::factory()->create(['course_id' => $course->id, 'title' => $code]);

        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $specification->id,
            'year_level' => $year,
            'sequence' => $sequence,
        ])->load('courseSpecification.course');
    }

    private function requirement(CurriculumEntry $target, CurriculumEntry $related, string $group, ?string $minimum = null, bool $acceptsTransfer = false): void
    {
        CourseRequirement::factory()->create([
            'course_specification_id' => $target->course_specification_id,
            'related_course_id' => $related->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => $group,
            'minimum_grade' => $minimum,
            'accepts_transfer_credit' => $acceptsTransfer,
            'state' => CourseRequirement::StateActive,
        ]);
    }

    private function grade(StudentProfile $profile, CurriculumEntry $entry, string $code, string $category): GradeRosterRow
    {
        $term = Term::factory()->create();
        $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id, 'term_id' => $term->id]);
        $courseEnrollment = CourseEnrollment::query()->create(['enrollment_id' => $enrollment->id, 'term_offering_id' => $offering->id, 'status' => CourseEnrollment::StatusActive]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $roster = GradeRoster::factory()->create(['term_offering_id' => $offering->id, 'section_id' => $section->id, 'faculty_user_id' => $faculty->id, 'state' => GradeRoster::StateReleased, 'released_at' => now()]);

        return GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $code,
            'current_outcome_category' => $category,
            'released_at' => now(),
        ]);
    }
}
