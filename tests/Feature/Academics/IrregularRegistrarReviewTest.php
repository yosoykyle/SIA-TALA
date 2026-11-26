<?php

namespace Tests\Feature\Academics;

use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Mail\AcademicRecordChangedMail;
use App\Models\AcademicDecision;
use App\Models\CurriculumVersion;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\BuildsIrregularStudents;
use Tests\TestCase;

class IrregularRegistrarReviewTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_registrar_queue_lists_flagged_students_and_confirm_irregular_records_the_decision(): void
    {
        Mail::fake();
        $flagged = $this->student();
        $regular = $this->student();
        $term = $this->term(now()->subMonths(5), now()->subMonths(1));
        $this->attempt($flagged, $term, $this->entry('IT-201', 'Networks'), '5.00', GradeRosterRow::CategoryFailed);
        $this->attempt($regular, $term, $this->entry('IT-202', 'Databases'), '2.00', GradeRosterRow::CategoryPassing);

        $this->actingAs($this->registrar);
        $this->assertSame('1', StudentProfileResource::getNavigationBadge());

        Livewire::actingAs($this->registrar)
            ->test(ListStudentProfiles::class)
            ->filterTable('irregular_review')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$regular]);

        Livewire::actingAs($this->registrar)
            ->test(ListStudentProfiles::class)
            ->assertTableActionVisible('confirmIrregular', $flagged)
            ->assertTableActionHidden('confirmIrregular', $regular);

        Livewire::actingAs($this->registrar)
            ->withQueryParams(['recordTab' => 'academic'])
            ->test(ViewStudentProfile::class, ['record' => $flagged->id])
            ->assertSee('Irregular review needed')
            ->assertSee('IT-201 – Networks (Grade: 5.00)')
            ->callAction(TestAction::make('confirmIrregular'), ['remarks' => 'Retake IT-201 next term.'])
            ->assertHasNoActionErrors();

        $decision = AcademicDecision::query()->where('student_profile_id', $flagged->id)->sole();
        $this->assertSame(AcademicDecision::EffectAdvisingRequired, $decision->effect);
        $this->assertStringContainsString('Confirmed irregular – Failed subject.', $decision->reason);
        $this->assertStringContainsString('Registrar remarks: Retake IT-201 next term.', $decision->reason);
        $this->assertSame(StudentProfile::StandingIrregular, $flagged->fresh()->academic_standing);
        Mail::assertQueued(AcademicRecordChangedMail::class, fn (AcademicRecordChangedMail $mail): bool => $mail->hasTo($flagged->user->email)
            && $mail->changeLabel === 'Your enrollment status: Irregular – Failed subject');
        $this->assertNull(StudentProfileResource::getNavigationBadge());
    }

    public function test_keep_regular_requires_a_reason_and_clears_the_flag(): void
    {
        $student = $this->student();
        $term = $this->term(now()->subMonths(5), now()->subMonths(1));
        $this->attempt($student, $term, $this->entry('IT-201', 'Networks'), '5.00', GradeRosterRow::CategoryFailed);

        $page = fn () => Livewire::actingAs($this->registrar)
            ->withQueryParams(['recordTab' => 'academic'])
            ->test(ViewStudentProfile::class, ['record' => $student->id]);
        $page()->callAction(TestAction::make('keepRegular'), ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        $page()->callAction(TestAction::make('keepRegular'), ['reason' => 'Grade appeal for IT-201 is pending.'])
            ->assertHasNoActionErrors();

        $decision = AcademicDecision::query()->where('student_profile_id', $student->id)->sole();
        $this->assertSame(AcademicDecision::EffectAllowed, $decision->effect);
        $this->assertSame('Kept regular: Grade appeal for IT-201 is pending.', $decision->reason);

        Livewire::actingAs($this->registrar)
            ->test(ListStudentProfiles::class)
            ->filterTable('irregular_review')
            ->assertCanNotSeeTableRecords([$student]);
    }
}
