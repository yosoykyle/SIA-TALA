<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Enrollment\EnrollmentContinuationSummary;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnrollmentContinuationSummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate(User::StaffRoleAccounting, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_continuation_summary_groups_year_level_progressions_and_excludes_graduated_or_exited_students(): void
    {
        $program = Program::factory()->create([
            'code' => 'DSET-CONT',
            'name' => 'Diploma in Software Engineering Technology',
            'duration_years' => 3,
        ]);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);

        $priorAy = AcademicYear::factory()->create([
            'label' => '2095-2096',
            'starts_on' => '2095-08-01',
            'ends_on' => '2096-05-31',
            'state' => AcademicYear::StateClosed,
        ]);
        $activeAy = AcademicYear::factory()->create([
            'label' => '2096-2097',
            'starts_on' => '2096-08-01',
            'ends_on' => '2097-05-31',
            'state' => AcademicYear::StateActive,
        ]);

        $priorTerm = Term::factory()->for($priorAy)->create([
            'type' => Term::TypeSecondSemester,
            'label' => 'Second Semester 2095-2096',
            'starts_on' => '2096-01-10',
            'ends_on' => '2096-05-20',
            'state' => Term::StateClosed,
        ]);
        $activeTerm = Term::factory()->for($activeAy)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester 2096-2097',
            'starts_on' => '2096-08-10',
            'ends_on' => '2096-12-15',
            'state' => Term::StateActive,
        ]);

        $priorYear1 = $this->cohort($priorTerm, $program, $curriculum, 1, 'DSET-1A');
        $priorYear2 = $this->cohort($priorTerm, $program, $curriculum, 2, 'DSET-2A');
        $priorYear3 = $this->cohort($priorTerm, $program, $curriculum, 3, 'DSET-3A');

        $activeYear2 = $this->cohort($activeTerm, $program, $curriculum, 2, 'DSET-2A');
        $activeYear3 = $this->cohort($activeTerm, $program, $curriculum, 3, 'DSET-3A');

        $enrolled1to2A = $this->student($program, $curriculum, 'Ana', 'Reyes');
        $enrolled1to2B = $this->student($program, $curriculum, 'Ben', 'Cruz');
        $droppedOut1 = $this->student($program, $curriculum, 'Clara', 'Diaz');
        $cancelled1 = $this->student($program, $curriculum, 'Dino', 'Lopez');

        $enrolled2to3 = $this->student($program, $curriculum, 'Elena', 'Santos');
        $inProgress2 = $this->student($program, $curriculum, 'Felix', 'Mendoza');

        $repeat3 = $this->student($program, $curriculum, 'Gina', 'Torres');

        $graduated3 = $this->student($program, $curriculum, 'Hector', 'Vega');
        $withdrawn1 = $this->student($program, $curriculum, 'Iris', 'Navarro', StudentProfile::LifecycleWithdrawn);
        $loa2 = $this->student($program, $curriculum, 'Joel', 'Aquino', StudentProfile::LifecycleLeaveOfAbsence);
        $completed3 = $this->student($program, $curriculum, 'Kaye', 'Bautista', StudentProfile::LifecycleCompleted);
        $freshmanActiveOnly = $this->student($program, $curriculum, 'Liam', 'Castro');

        foreach ([$enrolled1to2A, $enrolled1to2B, $droppedOut1, $cancelled1, $withdrawn1] as $profile) {
            $this->officialEnrollment($profile, $priorTerm, $priorYear1);
        }
        foreach ([$enrolled2to3, $inProgress2, $loa2] as $profile) {
            $this->officialEnrollment($profile, $priorTerm, $priorYear2);
        }
        foreach ([$repeat3, $graduated3, $completed3] as $profile) {
            $this->officialEnrollment($profile, $priorTerm, $priorYear3);
        }

        DegreeConferral::factory()->create([
            'student_profile_id' => $graduated3->id,
            'curriculum_version_id' => $curriculum->id,
        ]);

        $this->officialEnrollment($enrolled1to2A, $activeTerm, $activeYear2);
        $this->officialEnrollment($enrolled1to2B, $activeTerm, $activeYear2);
        Enrollment::factory()->for($cancelled1)->for($activeTerm)->create([
            'student_section_id' => $activeYear2->id,
            'canonical_outcome' => Enrollment::OutcomeCancelledByLearner,
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->officialEnrollment($enrolled2to3, $activeTerm, $activeYear3);
        Enrollment::factory()->for($inProgress2)->for($activeTerm)->create([
            'student_section_id' => $activeYear3->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'status' => 'pending_payment',
        ]);

        $this->officialEnrollment($repeat3, $activeTerm, $activeYear3);
        $this->officialEnrollment($freshmanActiveOnly, $activeTerm, $activeYear2);

        $summary = app(EnrollmentContinuationSummary::class)->forActiveTerm($activeTerm, $priorTerm);

        $this->assertSame(4, $summary['enrolled_count']);
        $this->assertSame(2, $summary['did_not_proceed_count']);
        $this->assertSame(1, $summary['in_progress_count']);
        $this->assertSame(7, $summary['prior_cohort_count']);

        $byLabel = collect($summary['progressions'])->keyBy('label');
        $this->assertSame(2, $byLabel['1st → 2nd']['enrolled']);
        $this->assertSame(2, $byLabel['1st → 2nd']['did_not_proceed']);
        $this->assertSame(1, $byLabel['2nd → 3rd']['enrolled']);
        $this->assertSame(0, $byLabel['2nd → 3rd']['did_not_proceed']);
        $this->assertSame(1, $byLabel['2nd → 3rd']['in_progress']);
        $this->assertSame(1, $byLabel['3rd → 3rd (repeat)']['enrolled']);
        $this->assertSame(0, $byLabel['3rd → 3rd (repeat)']['did_not_proceed']);

        $studentIds = collect($summary['students'])->pluck('student_profile_id')->all();
        $this->assertContains($droppedOut1->id, $studentIds);
        $this->assertContains($cancelled1->id, $studentIds);
        $this->assertContains($inProgress2->id, $studentIds);
        $this->assertNotContains($graduated3->id, $studentIds);
        $this->assertNotContains($withdrawn1->id, $studentIds);
        $this->assertNotContains($loa2->id, $studentIds);
        $this->assertNotContains($completed3->id, $studentIds);
        $this->assertNotContains($freshmanActiveOnly->id, $studentIds);
    }

    public function test_same_academic_year_continuation_keeps_same_year_level_without_repeat_label(): void
    {
        $program = Program::factory()->create(['code' => 'DIT-SAMEAY', 'duration_years' => 3]);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $academicYear = AcademicYear::factory()->create([
            'label' => '2097-2098',
            'starts_on' => '2097-08-01',
            'ends_on' => '2098-05-31',
            'state' => AcademicYear::StateActive,
        ]);

        $firstSem = Term::factory()->for($academicYear)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester 2097-2098',
            'starts_on' => '2097-08-10',
            'ends_on' => '2097-12-15',
            'state' => Term::StateClosed,
        ]);
        $secondSem = Term::factory()->for($academicYear)->create([
            'type' => Term::TypeSecondSemester,
            'label' => 'Second Semester 2097-2098',
            'starts_on' => '2098-01-10',
            'ends_on' => '2098-05-20',
            'state' => Term::StateActive,
        ]);

        $priorYear1 = $this->cohort($firstSem, $program, $curriculum, 1, 'DIT-1A');
        $activeYear1 = $this->cohort($secondSem, $program, $curriculum, 1, 'DIT-1A');

        $continued = $this->student($program, $curriculum, 'Mina', 'Flores');
        $didNotContinue = $this->student($program, $curriculum, 'Noah', 'Gomez');

        $this->officialEnrollment($continued, $firstSem, $priorYear1);
        $this->officialEnrollment($didNotContinue, $firstSem, $priorYear1);
        $this->officialEnrollment($continued, $secondSem, $activeYear1);

        $summary = app(EnrollmentContinuationSummary::class)->forActiveTerm($secondSem, $firstSem);

        $this->assertSame(1, $summary['enrolled_count']);
        $this->assertSame(1, $summary['did_not_proceed_count']);
        $this->assertCount(1, $summary['progressions']);
        $this->assertSame('1st → 1st', $summary['progressions'][0]['label']);
        $this->assertSame(1, $summary['progressions'][0]['enrolled']);
        $this->assertSame(1, $summary['progressions'][0]['did_not_proceed']);
    }

    public function test_registrar_workbench_replaces_make_official_card_with_continuation_card_and_breakdown_modal(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $program = Program::factory()->create([
            'code' => 'DSET-WB',
            'name' => 'Diploma in Software Engineering',
            'duration_years' => 3,
        ]);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);

        $priorAy = AcademicYear::factory()->create([
            'label' => '2198-2199',
            'starts_on' => '2198-08-01',
            'ends_on' => '2199-05-31',
            'state' => AcademicYear::StateClosed,
        ]);
        $activeAy = AcademicYear::factory()->create([
            'label' => '2199-2200',
            'starts_on' => '2199-08-01',
            'ends_on' => '2200-05-31',
            'state' => AcademicYear::StateActive,
        ]);

        $priorTerm = Term::factory()->for($priorAy)->create([
            'type' => Term::TypeSecondSemester,
            'label' => 'Second Semester 2198-2199',
            'starts_on' => '2199-01-15',
            'ends_on' => '2199-05-25',
            'state' => Term::StateClosed,
        ]);
        $activeTerm = Term::factory()->for($activeAy)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester 2199-2200',
            'starts_on' => '2199-08-15',
            'ends_on' => '2199-12-15',
            'state' => Term::StateActive,
        ]);

        $priorYear1 = $this->cohort($priorTerm, $program, $curriculum, 1, 'DSET-1A');
        $activeYear2 = $this->cohort($activeTerm, $program, $curriculum, 2, 'DSET-2A');

        $enrolledStudent = $this->student($program, $curriculum, 'Olivia', 'Ramos');
        $absentStudent = $this->student($program, $curriculum, 'Paolo', 'Garcia');

        $this->officialEnrollment($enrolledStudent, $priorTerm, $priorYear1);
        $this->officialEnrollment($absentStudent, $priorTerm, $priorYear1);
        $this->officialEnrollment($enrolledStudent, $activeTerm, $activeYear2);

        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->assertSet('activeTab', 'overview')
            ->assertSee('Prepare subjects, review enrollment continuation, or find a record.')
            ->assertSee('New enrollments to prepare')
            ->assertSee('Resolve enrollment issues')
            ->assertSee('Enrollment continuation')
            ->assertSee('enrolled this term')
            ->assertSee('1 did not proceed')
            ->assertSee('1st → 2nd')
            ->assertSee('View breakdown')
            ->assertSee('Waiting for payment or fee review')
            ->assertDontSee('Waiting for subject confirmation')
            ->assertDontSee('Make enrollment official')
            ->assertSeeHtml('role="region"')
            ->assertSeeHtml('aria-labelledby="registrar-continuation-heading"');

        $component
            ->mountAction('viewContinuationBreakdown')
            ->assertActionMounted('viewContinuationBreakdown')
            ->assertMountedActionModalSee('Enrollment continuation breakdown')
            ->assertMountedActionModalSee('Olivia Ramos')
            ->assertMountedActionModalSee('Paolo Garcia')
            ->assertMountedActionModalSee('DSET-WB')
            ->assertMountedActionModalSee('1st year')
            ->assertMountedActionModalSee('Enrolled')
            ->assertMountedActionModalSee('Did not proceed');

        $modalHtml = $component->getMountedActionModalHtml();
        $this->assertStringContainsString('tabindex="0"', $modalHtml);
        $this->assertStringContainsString('aria-labelledby="registrar-continuation-breakdown-heading"', $modalHtml);

        $component
            ->setActionData(['status' => EnrollmentContinuationSummary::StatusDidNotProceed])
            ->assertMountedActionModalSee('Paolo Garcia')
            ->assertMountedActionModalDontSee('Olivia Ramos')
            ->setActionData(['status' => EnrollmentContinuationSummary::StatusEnrolled])
            ->assertMountedActionModalSee('Olivia Ramos')
            ->assertMountedActionModalDontSee('Paolo Garcia');
    }

    public function test_non_registrar_cannot_access_continuation_summary_or_breakdown_action(): void
    {
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        $component = Livewire::actingAs($accounting)
            ->test(ListEnrollments::class)
            ->assertActionHidden('viewContinuationBreakdown');

        try {
            $component->instance()->enrollmentContinuationSummary();
            $this->fail('Accounting must not be allowed to read the Registrar enrollment continuation summary.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function cohort(
        Term $term,
        Program $program,
        CurriculumVersion $curriculum,
        int $yearLevel,
        string $reference,
    ): TermCohort {
        return TermCohort::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'year_level' => $yearLevel,
            'reference' => $reference,
        ]);
    }

    private function student(
        Program $program,
        CurriculumVersion $curriculum,
        string $firstName,
        string $lastName,
        string $lifecycleStatus = StudentProfile::LifecycleActive,
    ): StudentProfile {
        return StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'first_name' => $firstName,
            'middle_name' => null,
            'last_name' => $lastName,
            'lifecycle_status' => $lifecycleStatus,
        ]);
    }

    private function officialEnrollment(StudentProfile $profile, Term $term, TermCohort $cohort): Enrollment
    {
        return Enrollment::factory()->for($profile)->for($term)->create([
            'student_section_id' => $cohort->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
    }
}
