<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\RecordAcademicDecision;
use App\Filament\Student\Pages\Academics as StudentAcademics;
use App\Filament\Student\Pages\Dashboard as StudentDashboard;
use App\Models\AcademicDecision;
use App\Models\CourseRequirement;
use App\Models\CurriculumVersion;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\BuildsIrregularStudents;
use Tests\TestCase;

class IrregularStudentHubTest extends TestCase
{
    use BuildsIrregularStudents;
    use RefreshDatabase;

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
