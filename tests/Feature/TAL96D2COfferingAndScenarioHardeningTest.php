<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Actions\Scheduling\SectionPlanningService;
use App\Actions\SystemAdministration\CanonicalTalaSchedulingDataset;
use App\Filament\Resources\CurriculumVersions\Pages\ViewCurriculumVersion;
use App\Filament\Widgets\RegistrarOperationalReadinessWidget;
use App\Models\AcademicYear;
use App\Models\AdmissionRequirementPolicy;
use App\Models\Assessment;
use App\Models\CalendarEvent;
use App\Models\ChecklistItem;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\FeeRule;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class TAL96D2COfferingAndScenarioHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        $this->clearOperationalDataInsideTestTransaction();
    }

    public function test_only_approved_modalities_are_available(): void
    {
        $expected = [
            TermOffering::ModalityOnline => 'Online',
            TermOffering::ModalityFaceToFace => 'Face-to-Face',
        ];

        $this->assertSame($expected, TermOffering::modalityOptions());
        $this->assertSame($expected, SectionDeliveryGroup::modalityOptions());
        $this->assertSame('21:00:00', Term::factory()->make()->scheduling_day_ends_at);
    }

    public function test_direct_section_save_requires_a_term_unique_source_record_code(): void
    {
        $term = Term::factory()->create();
        $firstOffering = TermOffering::factory()->for($term)->create();
        $secondOffering = TermOffering::factory()->for($term)->create();
        Section::factory()->for($firstOffering)->create(['code' => 'DIT-1A-CC101']);

        try {
            app(SectionPlanningService::class)->prepareForSave([
                'term_offering_id' => $secondOffering->id,
                'code' => 'DIT-1A-CC101',
                'capacity' => 30,
                'state' => Section::StatePlanned,
            ]);
            $this->fail('A duplicate Section source-record code in the same Term was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $otherTermOffering = TermOffering::factory()->create();
        $prepared = app(SectionPlanningService::class)->prepareForSave([
            'term_offering_id' => $otherTermOffering->id,
            'code' => 'DIT-1A-CC101',
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);

        $this->assertSame('DIT-1A-CC101', $prepared['code']);
    }

    public function test_direct_delivery_group_save_enforces_course_modality_and_friendly_name_uniqueness(): void
    {
        $specification = CourseSpecification::factory()->create([
            'allowed_modalities' => [TermOffering::ModalityOnline],
        ]);
        $entry = CurriculumEntry::factory()->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityOnline,
        ]);
        $section = Section::factory()->for($offering)->create();
        SectionDeliveryGroup::factory()->for($section)->create(['name' => 'DIT-1A']);

        foreach ([
            [
                'name' => 'DIT-1A',
                'expected_count' => 30,
                'modality' => TermOffering::ModalityOnline,
                'state' => SectionDeliveryGroup::StateReady,
            ],
            [
                'name' => 'DIT-1B',
                'expected_count' => 30,
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionDeliveryGroup::StateReady,
            ],
        ] as $data) {
            try {
                app(SectionDeliveryGroupService::class)->prepareForSave($section, $data);
                $this->fail('An invalid delivery-group change was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_canonical_dataset_defines_the_current_scheduling_workload(): void
    {
        $dataset = app(CanonicalTalaSchedulingDataset::class);
        $manifest = $dataset->manifest();

        $this->assertSame('CANONICAL_TALA_SCHEDULING_DATASET', $manifest['dataset']);
        $this->assertSame([
            'students' => 47,
            'cohorts' => 6,
            'faculty' => 9,
            'offerings' => 54,
            'sections' => 54,
            'scheduling_demands' => 54,
        ], $manifest['counts']);
        $this->assertCount(10, $dataset->roomDefinitions());
        $this->assertSame('PASS', $manifest['faculty_evidence']['bounded_readiness']);
        $this->assertSame([], $manifest['faculty_evidence']['unassignable_workloads']);
    }

    public function test_registrar_dashboard_explains_the_academic_setup_and_scheduling_order(): void
    {
        $this->assertCanonicalDatasetCreatesExpectedWorkload();

        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
        $this->actingAs($registrar);

        $this->assertTrue(RegistrarOperationalReadinessWidget::canView());

        Livewire::test(RegistrarOperationalReadinessWidget::class)
            ->assertSee('1. Academic Period')
            ->assertSee('AY 2025-2026 / Second Semester')
            ->assertSee('2. Active Curricula')
            ->assertSee('3 programs ready')
            ->assertSee('3. Offerings & Sections')
            ->assertSee('54 offerings / 54 sections')
            ->assertSee('4. Teaching Resources')
            ->assertSee('9 faculty / 10 rooms')
            ->assertSee('5. Schedule Requirements')
            ->assertSee('54 ready for review')
            ->assertSee('6. Published Timetable')
            ->assertSee('Not published');

        $this->get('/admin')
            ->assertOk()
            ->assertSee(RegistrarOperationalReadinessWidget::class, false);

        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $this->actingAs($accounting);

        $this->assertFalse(RegistrarOperationalReadinessWidget::canView());
    }

    public function test_curriculum_review_presents_the_source_order_and_course_facts_in_a_readable_table(): void
    {
        $this->assertCanonicalDatasetCreatesExpectedWorkload();

        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
        $curriculum = CurriculumVersion::query()
            ->where('state', CurriculumVersion::StateActive)
            ->whereHas('program', fn ($query) => $query->where('code', 'DBM'))
            ->sole();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewCurriculumVersion::class, ['record' => $curriculum->id])
            ->assertSee('Curriculum Entries')
            ->assertSee('Year Level')
            ->assertSee('Term')
            ->assertSee('Sequence')
            ->assertSee('Course Code')
            ->assertSee('Course Title')
            ->assertSee('Units')
            ->assertSee('Requirement')
            ->assertSee('Third Year')
            ->assertSee('Second Semester')
            ->assertSee('THC09')
            ->assertSee('International Business and Trade')
            ->assertSee('3.00');
    }

    public function test_exploration_personas_are_grounded_in_owner_correct_prior_term_records(): void
    {
        $this->assertCanonicalDatasetCreatesExpectedWorkload();

        $exitCode = Artisan::call('acceptance:seed-tal96d5e1-exploration');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('coverage_state=PASS', $output);

        $priorTerm = Term::query()
            ->where('type', Term::TypeFirstSemester)
            ->where('label', 'First Semester')
            ->where('state', Term::StateClosed)
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();

        foreach ([
            'student.demo@example.test',
            'student.dbm-2a.002@example.test',
            'student.dit-2a.002@example.test',
            'student.dthm-1a.002@example.test',
            'student.dthm-2a.001@example.test',
            'student.completion.demo@example.test',
            'student.graduation.demo@example.test',
        ] as $email) {
            $profile = User::query()->where('email', $email)->sole()->studentProfile()->sole();

            $this->assertTrue($this->priorReleasedOutcomeExists(
                $profile,
                $priorTerm,
                GradeRosterRow::CategoryPassing,
            ));
        }

        foreach ([
            'student.dbm-2a.001@example.test',
            'student.dit-1a.001@example.test',
            'student.dit-2a.001@example.test',
            'student.dthm-1a.001@example.test',
        ] as $email) {
            $profile = User::query()->where('email', $email)->sole()->studentProfile()->sole();

            $this->assertTrue($this->priorReleasedOutcomeExists(
                $profile,
                $priorTerm,
                GradeRosterRow::CategoryFailed,
            ));
        }

        $deficient = User::query()
            ->where('email', 'student.dit-1a.002@example.test')
            ->sole()
            ->studentProfile()
            ->sole();
        $this->assertTrue($this->priorReleasedOutcomeExists(
            $deficient,
            $priorTerm,
            GradeRosterRow::CategoryIncomplete,
        ));

        $prerequisiteBlocked = User::query()
            ->where('email', 'student.dit-2a.001@example.test')
            ->sole()
            ->studentProfile()
            ->sole();
        $this->assertTrue(Hold::query()
            ->whereBelongsTo($prerequisiteBlocked)
            ->whereBelongsTo($priorTerm)
            ->where('hold_type', Hold::TypePrerequisite)
            ->where('blocking_level', Hold::BlockingEnrollment)
            ->where('status', Hold::StatusActive)
            ->exists());

        $notEvaluated = User::query()
            ->where('email', 'student.dthm-2a.002@example.test')
            ->sole()
            ->studentProfile()
            ->sole();
        $this->assertTrue(Enrollment::query()
            ->whereBelongsTo($notEvaluated)
            ->whereBelongsTo($priorTerm)
            ->exists());
        $this->assertFalse($this->priorReleasedOutcomeExists(
            $notEvaluated,
            $priorTerm,
            GradeRosterRow::CategoryPassing,
        ));

        foreach ([
            'student.completion.demo@example.test' => 'Ready for Registrar Review',
            'student.graduation.demo@example.test' => 'Complete',
        ] as $email => $resultStatus) {
            $profile = User::query()->where('email', $email)->sole()->studentProfile()->sole();

            $this->assertTrue(GraduationSnapshot::query()
                ->whereHas(
                    'member',
                    fn ($query) => $query
                        ->whereBelongsTo($profile)
                        ->where('is_active', true),
                )
                ->where('result_status', $resultStatus)
                ->whereNotNull('made_visible_at')
                ->exists());
        }

        $this->assertTrue(GradeRoster::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($priorTerm))
            ->where('state', GradeRoster::StateReleased)
            ->whereIn(
                'faculty_user_id',
                User::role(User::StaffRoleFaculty)->select('users.id'),
            )
            ->whereHas('rows')
            ->exists());
        $this->assertTrue(GraduationReviewMember::query()->where('is_active', true)->exists());
    }

    private function assertCanonicalDatasetCreatesExpectedWorkload(): string
    {
        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=created', $output);
        $this->assertStringContainsString('students=47', $output);
        $this->assertStringContainsString('scheduling_demands=54', $output);
        $this->assertStringContainsString('admission_requirement_policies=10', $output);
        $this->assertSame(47, StudentProfile::query()->count());
        $this->assertSame(54, TermOffering::query()->count());
        $this->assertSame(54, Section::query()->count());
        $this->assertSame(54, SectionDeliveryGroup::query()->count());
        $this->assertSame(54, SchedulingDemand::query()->count());
        $this->assertSame(9, User::role(User::StaffRoleFaculty)->count());
        $this->assertSame(6, SectionDeliveryGroup::query()->distinct()->count('name'));
        $this->assertSame(10, AdmissionRequirementPolicy::query()->count());
        $this->assertSame(
            7,
            AdmissionRequirementPolicy::query()
                ->where('evidence_method', ChecklistItem::EvidenceMethodDigitalUpload)
                ->count(),
        );
        $this->assertSame(
            2,
            AdmissionRequirementPolicy::query()
                ->where('evidence_method', ChecklistItem::EvidenceMethodPhysicalCopy)
                ->count(),
        );
        $this->assertSame(
            1,
            AdmissionRequirementPolicy::query()
                ->where('evidence_method', ChecklistItem::EvidenceMethodMetadataOnly)
                ->count(),
        );
        $this->assertSame(
            54,
            SchedulingDemand::query()
                ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
                ->count(),
        );
        $term = Term::query()
            ->where('type', Term::TypeSecondSemester)
            ->where('state', Term::StateActive)
            ->sole();
        $this->assertSame('21:00:00', $term->scheduling_day_ends_at);

        return $output;
    }

    private function priorReleasedOutcomeExists(
        StudentProfile $profile,
        Term $term,
        string $category,
    ): bool {
        return GradeRosterRow::query()
            ->where('current_outcome_category', $category)
            ->whereNotNull('released_at')
            ->whereHas(
                'courseEnrollment.enrollment',
                fn ($query) => $query
                    ->whereBelongsTo($profile)
                    ->whereBelongsTo($term),
            )
            ->exists();
    }

    private function clearOperationalDataInsideTestTransaction(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::table('document_evidence')->delete();
            DB::table('checklist_items')->delete();
            DB::table('applicant_intakes')->delete();
            DB::table('activity_log')->delete();
            AdmissionRequirementPolicy::query()->delete();
            SchedulingDemand::query()->delete();
            SectionDeliveryGroup::query()->delete();
            Section::query()->delete();
            TermOffering::query()->delete();
            FeeRule::query()->delete();
            FacultyQualification::query()->delete();
            FacultyTermLoadOverride::query()->delete();
            CalendarEvent::query()->delete();
            DB::table('graduation_snapshots')->delete();
            DB::table('graduation_review_members')->delete();
            DB::table('graduation_review_batches')->delete();
            DB::table('program_shift_credit_entries')->delete();
            DB::table('student_lifecycle_changes')->delete();
            DB::table('holds')->delete();
            DB::table('grade_outcome_events')->delete();
            DB::table('grade_roster_rows')->delete();
            DB::table('grade_rosters')->delete();
            DB::table('student_schedule_bindings')->delete();
            DB::table('enrollment_gate_results')->delete();
            DB::table('enrollment_exceptions')->delete();
            DB::table('enrollment_seat_reservations')->delete();
            DB::table('course_enrollments')->delete();
            SectionMeeting::query()->delete();
            ScheduleGenerationRun::query()->delete();
            Enrollment::query()->delete();
            Assessment::query()->delete();
            LedgerEntry::query()->delete();
            PaymentAttempt::query()->delete();
            Payment::query()->delete();
            StudentProfile::query()->delete();
            CurriculumEntry::query()->delete();
            CurriculumVersion::query()->delete();
            CourseComponent::query()->delete();
            CourseSpecification::query()->delete();
            Course::query()->delete();
            Room::query()->delete();
            Program::query()->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->delete();
            User::query()->delete();
            Term::query()->delete();
            AcademicYear::query()->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
