<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\RecordAcademicDecision;
use App\Filament\Student\Pages\Academics as StudentAcademics;
use App\Filament\Student\Pages\Dashboard as StudentDashboard;
use App\Models\AcademicDecision;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\BuildsIrregularStudents;
use Tests\TestCase;

class IrregularStudentHubTest extends TestCase
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

    public function test_student_sees_flag_then_confirmed_status_and_color_coded_checklist(): void
    {
        $student = $this->failedStudentWithDependent();
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($student->user)
            ->test(StudentDashboard::class)
            ->assertSee('Flagged for irregular review')
            ->assertSee('IT-101A – Data Structures (Grade: 5.00)')
            ->assertSee('The Registrar will confirm your status before your next enrollment.');

        $this->confirmAsRegistrar($student);

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($student->user)
            ->test(StudentDashboard::class)
            ->assertSee('Status: Irregular – Failed subject')
            ->assertSee('Primary reason')
            ->assertSee('You need to apply for irregular enrollment for the next term.');

        Livewire::actingAs($student->user)
            ->withQueryParams(['view' => 'plan'])
            ->test(StudentAcademics::class)
            ->assertSee('Subject checklist summary', false)
            ->assertSee('Failed – need to retake')
            ->assertSee('Locked – prerequisite not passed (IT-101A)')
            ->assertSee('data-checklist-state="failed"', false)
            ->assertSee('data-checklist-state="locked"', false);
    }

    public function test_confirmed_irregular_student_enrolled_this_term_is_not_told_to_apply_again(): void
    {
        $student = $this->failedStudentWithDependent();
        $this->confirmAsRegistrar($student);
        $currentTerm = $this->term(now()->subWeek(), now()->addMonths(3), Term::TypeSecondSemester, Term::StateActive);
        $retake = TermOffering::factory()->create([
            'term_id' => $currentTerm->id,
            'curriculum_entry_id' => CurriculumEntry::query()
                ->whereHas('courseSpecification.course', fn ($course) => $course->where('code', 'IT-101A'))
                ->sole()->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $currentTerm->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $retake->id,
            'section_id' => Section::factory()->create(['term_offering_id' => $retake->id])->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3.00,
            'added_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($student->user)
            ->test(StudentDashboard::class)
            ->assertSee('Status: Irregular')
            ->assertSee('Your status is reviewed again once grades are released.')
            ->assertDontSee('You need to apply for irregular enrollment for the next term.');
    }

    private function failedStudentWithDependent(): StudentProfile
    {
        $student = $this->student();
        $term = $this->term(now()->subMonths(5), now()->subMonths(1));
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

        return $student;
    }

    private function confirmAsRegistrar(StudentProfile $student): void
    {
        app(RecordAcademicDecision::class)->execute(
            $student,
            null,
            AcademicDecision::EffectAdvisingRequired,
            null,
            null,
            'Confirmed irregular – Failed subject.',
            today(),
            null,
            $this->registrar,
            StudentProfile::StandingIrregular,
        );
    }
}
