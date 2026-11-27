<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Enrollment\RegistrationSelectionBasisResolver;
use App\Models\AcademicDecision;
use App\Models\CourseRequirement;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\BuildsIrregularStudents;
use Tests\TestCase;

class IrregularStudentReasonsTest extends TestCase
{
    use BuildsIrregularStudents;
    use DatabaseTransactions;

    private User $registrar;

    private User $faculty;

    private CurriculumVersion $curriculum;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        $this->registrar = $this->staff(User::StaffRoleRegistrar);
        $this->faculty = $this->staff(User::StaffRoleFaculty);
        $this->curriculum = CurriculumVersion::factory()->create(['program_id' => Program::factory()->create()->id]);
    }

    public function test_failed_subject_names_the_subject_and_locks_its_dependent(): void
    {
        $student = $this->student();
        $term = $this->term(now()->subMonths(6), now()->subMonths(2));
        $dataStructures = $this->entry('IT-101A', 'Data Structures');
        $advanced = $this->entry('IT-101B', 'Advanced Data Structures');
        CourseRequirement::factory()->create([
            'course_specification_id' => $advanced->course_specification_id,
            'related_course_id' => $dataStructures->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'g1',
            'state' => CourseRequirement::StateActive,
        ]);
        $this->attempt($student, $term, $dataStructures, '5.00', GradeRosterRow::CategoryFailed);

        $reasons = app(IrregularStudentReasons::class)->forStudent($student);

        $this->assertTrue($reasons['is_irregular']);
        $this->assertSame('Irregular – Failed subject', $reasons['status_label']);
        $this->assertSame(IrregularStudentReasons::ReasonFailed, $reasons['primary']['code']);
        $this->assertStringContainsString('IT-101A – Data Structures (Grade: 5.00)', $reasons['primary']['details']);
        $this->assertSame(['IT-101A'], $reasons['primary']['subjects']);
        $prerequisite = collect($reasons['reasons'])->firstWhere('code', IrregularStudentReasons::ReasonPrerequisite);
        $this->assertSame(['IT-101A → cannot take IT-101B'], $prerequisite['subjects']);
    }

    public function test_open_inc_shows_the_compliance_deadline_wording(): void
    {
        $student = $this->student();
        $term = $this->term(now()->subMonths(6), now()->subMonths(2));
        $this->attempt($student, $term, $this->entry('GE-105', 'Ethics'), 'INC', GradeRosterRow::CategoryIncomplete);

        $reasons = app(IrregularStudentReasons::class)->forStudent($student);

        $this->assertTrue($reasons['is_irregular']);
        $this->assertSame(IrregularStudentReasons::ReasonIncomplete, $reasons['primary']['code']);
        $this->assertStringContainsString('GE-105 – Ethics', $reasons['primary']['details']);
    }

    public function test_keep_regular_decision_hides_the_irregular_label(): void
    {
        $student = $this->student();
        $term = $this->term(now()->subMonths(6), now()->subMonths(2));
        $this->attempt($student, $term, $this->entry('IT-201', 'Networks'), '5.00', GradeRosterRow::CategoryFailed);
        AcademicDecision::query()->create([
            'student_profile_id' => $student->id,
            'effect' => AcademicDecision::EffectAllowed,
            'reason' => 'Grade appeal pending for IT-201.',
            'effective_from' => today()->toDateString(),
            'state' => AcademicDecision::StateActive,
            'recorded_by' => $this->registrar->id,
            'recorded_at' => now(),
        ]);

        $reasons = app(IrregularStudentReasons::class)->forStudent($student);

        $this->assertFalse($reasons['is_irregular']);
        $this->assertTrue($reasons['kept_regular']);
        $this->assertSame('Regular', $reasons['status_label']);
        $this->assertSame([], $reasons['reasons']);
    }

    public function test_returnee_after_a_skipped_semester_is_routed_to_individually_advised(): void
    {
        $student = $this->student();
        $first = $this->term(now()->subMonths(14), now()->subMonths(10), Term::TypeFirstSemester, Term::StateClosed);
        $this->term(now()->subMonths(9), now()->subMonths(5), Term::TypeSecondSemester, Term::StateClosed);
        $this->attempt($student, $first, $this->entry('IT-100', 'Intro'), '2.00', GradeRosterRow::CategoryPassing);

        $reasons = app(IrregularStudentReasons::class)->forStudent($student);

        $this->assertTrue($reasons['is_irregular']);
        $this->assertSame(IrregularStudentReasons::ReasonReturnee, $reasons['primary']['code']);
        $this->assertStringContainsString('Not enrolled:', $reasons['primary']['details']);
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, app(RegistrationSelectionBasisResolver::class)->forContinuingStudent($student));
    }

    public function test_continuous_passing_student_stays_regular_and_standard(): void
    {
        $student = $this->student();
        $term = $this->term(now()->subMonths(5), now()->subMonths(1), Term::TypeFirstSemester, Term::StateClosed);
        $this->attempt($student, $term, $this->entry('IT-100', 'Intro'), '1.75', GradeRosterRow::CategoryPassing);

        $reasons = app(IrregularStudentReasons::class)->forStudent($student);

        $this->assertFalse($reasons['is_irregular']);
        $this->assertSame('Regular', $reasons['status_label']);
        $this->assertSame(Enrollment::SelectionStandardCurriculum, app(RegistrationSelectionBasisResolver::class)->forContinuingStudent($student));
    }
}
