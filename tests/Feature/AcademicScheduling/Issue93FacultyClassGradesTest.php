<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Actions\Grades\SaveGradeRosterDraft;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Pages\FacultyGradeRoster;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\IncCompletionSubmission;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue93FacultyClassGradesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
            'student',
        ] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function class_grades_retires_roster_jargon_and_supports_queue_sub_options_and_reactive_filters(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $openTerm = $this->termWithGradeWindow(open: true, label: 'First Semester 2026');
        $closedTerm = $this->termWithGradeWindow(open: false, label: 'Second Semester 2026');

        $draftOpen = $this->classRosterFixture($registrar, $faculty, $openTerm, 'CLS-DRAFT-OPEN', 'Algorithms and Complexity', 2);
        $firstDraftRow = $draftOpen->rows()->orderBy('id')->firstOrFail();
        $firstDraftRow->update(['final_result' => '1.50']);

        $returnedClass = $this->classRosterFixture($registrar, $faculty, $openTerm, 'CLS-RETURNED', 'Operating Systems', 1);
        $returnedRow = $returnedClass->rows()->sole();
        app(SaveGradeRosterDraft::class)->execute($returnedClass, [
            ['id' => $returnedRow->id, 'final_result' => '2.00', 'inc_completion_note' => null],
        ], $returnedClass->lock_version, $returnedClass->membership_signature, $faculty);
        $returnedClass = app(SubmitGradeRoster::class)->execute($returnedClass->fresh(), $faculty);
        app(ReturnGradeRoster::class)->execute($returnedClass, $registrar, 'Verify lab exam score.', [$returnedRow->id]);

        $submittedClass = $this->classRosterFixture($registrar, $faculty, $openTerm, 'CLS-SUBMITTED', 'Database Systems', 1);
        $submittedRow = $submittedClass->rows()->sole();
        app(SaveGradeRosterDraft::class)->execute($submittedClass, [
            ['id' => $submittedRow->id, 'final_result' => '1.75', 'inc_completion_note' => null],
        ], $submittedClass->lock_version, $submittedClass->membership_signature, $faculty);
        app(SubmitGradeRoster::class)->execute($submittedClass->fresh(), $faculty);

        $releasedIncClass = $this->classRosterFixture($registrar, $faculty, $openTerm, 'CLS-INC-OPEN', 'Capstone Project', 1);
        $incRow = $releasedIncClass->rows()->sole();
        app(SaveGradeRosterDraft::class)->execute($releasedIncClass, [
            ['id' => $incRow->id, 'final_result' => 'INC', 'inc_completion_note' => 'Defend final prototype.'],
        ], $releasedIncClass->lock_version, $releasedIncClass->membership_signature, $faculty);
        $releasedIncClass = app(SubmitGradeRoster::class)->execute($releasedIncClass->fresh(), $faculty);
        app(PostAndReleaseGradeRoster::class)->execute($releasedIncClass, $registrar, 'REL-INC-001');

        $closedDraft = $this->classRosterFixture($registrar, $faculty, $closedTerm, 'CLS-CLOSED-DRAFT', 'Discrete Structures', 1);

        $this->assertSame('Class grades', FacultyGradeRoster::getNavigationLabel());

        Livewire::withQueryParams(['queue' => 'all'])
            ->actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', null)
            ->assertSet('queue', 'all')
            ->assertSee('Class grades')
            ->assertSee('CLS-DRAFT-OPEN')
            ->assertSee('1 of 2 graded')
            ->assertSee('CLS-RETURNED')
            ->assertSee('Changes requested')
            ->assertSee('CLS-SUBMITTED')
            ->assertSee('Submitted for Registrar review')
            ->assertSee('CLS-INC-OPEN')
            ->assertSee('1 open INC')
            ->assertSee('CLS-CLOSED-DRAFT')
            ->set('queue', 'needs-action')
            ->assertSee('CLS-DRAFT-OPEN')
            ->assertSee('CLS-RETURNED')
            ->assertDontSee('CLS-SUBMITTED')
            ->assertDontSee('CLS-INC-OPEN')
            ->assertDontSee('CLS-CLOSED-DRAFT')
            ->set('queue', 'inc')
            ->assertSee('CLS-INC-OPEN')
            ->assertDontSee('CLS-DRAFT-OPEN')
            ->assertDontSee('CLS-RETURNED')
            ->assertDontSee('CLS-SUBMITTED')
            ->set('queue', 'all')
            ->set('classTerm', (string) $closedTerm->id)
            ->assertSee('CLS-CLOSED-DRAFT')
            ->assertDontSee('CLS-DRAFT-OPEN')
            ->set('classSearch', 'NoSuchCourseXYZ')
            ->assertSee('No classes match your filters')
            ->call('clearClassFilters')
            ->assertSet('queue', 'all')
            ->assertSet('classTerm', '')
            ->assertSet('classSearch', '')
            ->assertSee('CLS-DRAFT-OPEN');

        Livewire::withQueryParams(['queue' => 'needs-action'])
            ->actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', null)
            ->assertSee('CLS-DRAFT-OPEN')
            ->assertSee('CLS-RETURNED')
            ->assertDontSee('CLS-SUBMITTED');

        Livewire::withQueryParams(['queue' => 'inc'])
            ->actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', null)
            ->assertSee('CLS-INC-OPEN')
            ->assertDontSee('CLS-DRAFT-OPEN');

        Livewire::withQueryParams(['termId' => $closedTerm->id])
            ->actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', null)
            ->assertSet('classTerm', (string) $closedTerm->id)
            ->assertSee('CLS-CLOSED-DRAFT')
            ->assertDontSee('CLS-DRAFT-OPEN');

        $this->assertNotNull($closedDraft->id);
    }

    #[Test]
    public function class_workspace_eliminates_class_details_dropdown_and_moves_handoff_and_inc_inline(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        $term = $this->termWithGradeWindow(open: true, label: 'First Semester 2026');

        $draftRoster = $this->classRosterFixture($registrar, $faculty, $term, 'SEC-SINGLE', 'Software Engineering', 2);
        app(ManageTeachingAssignment::class)->addCoFaculty($draftRoster->section, $coFaculty, $registrar, 'MEMO-CO-99');

        Livewire::actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', $draftRoster->id)
            ->assertSee('Print class list')
            ->assertSee('Export class list')
            ->assertSee('Submit class grades')
            ->assertSee('Grade entry open')
            ->set('studentSearch', $draftRoster->rows->first()->courseEnrollment->enrollment->studentProfile->student_number)
            ->assertSee('1 of 2 students shown');

        Livewire::actingAs($coFaculty)
            ->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', $draftRoster->id)
            ->assertSee('View-only · Designated faculty: '.$faculty->name);

        $rows = $draftRoster->rows()->orderBy('id')->get();
        app(SaveGradeRosterDraft::class)->execute($draftRoster, [
            ['id' => $rows[0]->id, 'final_result' => '2.00', 'inc_completion_note' => null],
            ['id' => $rows[1]->id, 'final_result' => 'INC', 'inc_completion_note' => 'Submit final project report.'],
        ], $draftRoster->lock_version, $draftRoster->membership_signature, $faculty);

        $submitted = app(SubmitGradeRoster::class)->execute($draftRoster->fresh(), $faculty);

        Livewire::actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertSee('Submitted · ')
            ->assertSee('Current responsibility and next step');

        $released = app(PostAndReleaseGradeRoster::class)->execute($submitted->fresh(), $registrar, 'REL-INLINE-01');
        $sortedRows = $released->rows()->get()->sortBy(fn ($row): string => collect([
            $row->courseEnrollment?->enrollment?->studentProfile?->last_name,
            $row->courseEnrollment?->enrollment?->studentProfile?->first_name,
            $row->courseEnrollment?->enrollment?->studentProfile?->middle_name,
            $row->courseEnrollment?->enrollment?->studentProfile?->student_number,
        ])->filter()->implode('|'))->values();

        $numericRow = $sortedRows->firstWhere('current_outcome_code', '2.00');
        $incRow = $sortedRows->firstWhere('current_outcome_code', 'INC');
        $this->assertNotNull($numericRow);
        $this->assertNotNull($incRow);

        $page = Livewire::actingAs($faculty)->test(FacultyGradeRoster::class);
        $page->call('selectStudent', $numericRow->id)
            ->assertActionHidden('submitIncCompletion')
            ->call('selectStudent', $incRow->id)
            ->assertActionVisible('submitIncCompletion')
            ->callAction('submitIncCompletion', [
                'proposed_result' => '1.75',
                'completion_note' => 'Final project report defended and graded.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('INC completion submitted for Registrar review')
            ->assertActionHidden('submitIncCompletion')
            ->assertSee('Ready for Registrar review');

        $this->assertSame(1, IncCompletionSubmission::query()->where('proposed_result', '1.75')->count());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function termWithGradeWindow(bool $open, string $label): Term
    {
        $term = Term::factory()->create([
            'label' => $label,
            'starts_on' => now()->subMonths(2)->toDateString(),
            'ends_on' => now()->addMonths(2)->toDateString(),
            'state' => Term::StateActive,
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => $open ? now()->subDay()->toDateString() : now()->subDays(10)->toDateString(),
            'closes_on' => $open ? now()->addDays(5)->toDateString() : now()->subDays(2)->toDateString(),
            'cutoff_at' => '17:00:00',
        ]);

        return $term;
    }

    private function classRosterFixture(User $registrar, User $faculty, Term $term, string $sectionCode, string $courseTitle, int $studentCount): GradeRoster
    {
        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'CS-'.substr(md5($sectionCode), 0, 4)]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => $courseTitle,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => $sectionCode,
            'state' => Section::StateOpen,
        ]);

        for ($i = 0; $i < $studentCount; $i++) {
            $student = StudentProfile::factory()->for($program)->for($curriculum)->create();
            $enrollment = Enrollment::factory()->for($term)->create([
                'student_profile_id' => $student->id,
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'officially_enrolled_at' => now(),
            ]);
            CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
                'section_id' => $section->id,
                'status' => CourseEnrollment::StatusActive,
                'is_current' => true,
                'units_snapshot' => '3.00',
                'added_at' => now(),
            ]);
        }

        app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'ASSIGN-'.$sectionCode);

        return app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar);
    }
}
