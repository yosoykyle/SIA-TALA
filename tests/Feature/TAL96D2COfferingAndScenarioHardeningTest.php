<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Actions\Scheduling\SectionPlanningService;
use App\Actions\SystemAdministration\SchedulingAcceptanceScenarioCatalog;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\FeeRule;
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
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function test_scenario_catalog_defines_deterministic_min_middle_and_max_workloads(): void
    {
        $catalog = app(SchedulingAcceptanceScenarioCatalog::class);

        $this->assertSame([
            'MIN' => [
                'students' => 47,
                'cohorts' => 6,
                'faculty' => 9,
                'offerings' => 54,
                'sections' => 54,
                'scheduling_demands' => 54,
            ],
            'MIDDLE' => [
                'students' => 270,
                'cohorts' => 9,
                'faculty' => 14,
                'offerings' => 80,
                'sections' => 80,
                'scheduling_demands' => 80,
            ],
            'MAX' => [
                'students' => 600,
                'cohorts' => 20,
                'faculty' => 26,
                'offerings' => 80,
                'sections' => 178,
                'scheduling_demands' => 178,
            ],
        ], collect($catalog->keys())
            ->mapWithKeys(fn (string $key): array => [$key => $catalog->manifest($key)['counts']])
            ->all());

        foreach ($catalog->keys() as $key) {
            $manifest = $catalog->manifest($key);

            $this->assertSame([1, 2, 3, 4, 5, 6], $manifest['operating_grid']['days']);
            $this->assertSame('07:00:00', $manifest['operating_grid']['starts_at']);
            $this->assertSame('21:00:00', $manifest['operating_grid']['ends_at']);
            $this->assertSame(30, $manifest['operating_grid']['slot_minutes']);
            $this->assertNotSame('', $manifest['basis']);
            $this->assertNotSame('', $manifest['limitation']);
            $this->assertSame('PASS', $manifest['faculty_evidence']['bounded_readiness']);
            $this->assertSame([], $manifest['faculty_evidence']['unassignable_workloads']);
            $this->assertSame(
                'FULL_OPERATING_GRID',
                $manifest['faculty_evidence']['availability_assumption'],
            );
            $this->assertLessThanOrEqual(
                $manifest['faculty_evidence']['max_units_per_faculty'],
                $manifest['faculty_evidence']['maximum_constructed_load'],
            );
        }

        $this->assertSame([
            'MIN' => [
                'client_reported_faculty' => 9,
                'synthetic_scheduling_faculty' => 9,
                'total_teaching_units' => 162.0,
                'arithmetic_faculty_lower_bound' => 8,
            ],
            'MIDDLE' => [
                'client_reported_faculty' => null,
                'synthetic_scheduling_faculty' => 14,
                'total_teaching_units' => 240.0,
                'arithmetic_faculty_lower_bound' => 12,
            ],
            'MAX' => [
                'client_reported_faculty' => 14,
                'synthetic_scheduling_faculty' => 26,
                'total_teaching_units' => 532.0,
                'arithmetic_faculty_lower_bound' => 26,
            ],
        ], collect($catalog->keys())
            ->mapWithKeys(function (string $key) use ($catalog): array {
                $evidence = $catalog->manifest($key)['faculty_evidence'];

                return [$key => [
                    'client_reported_faculty' => $evidence['client_reported_faculty'],
                    'synthetic_scheduling_faculty' => $evidence['synthetic_scheduling_faculty'],
                    'total_teaching_units' => $evidence['total_teaching_units'],
                    'arithmetic_faculty_lower_bound' => $evidence['arithmetic_faculty_lower_bound'],
                ]];
            })
            ->all());
    }

    public function test_middle_scenario_is_executable_ready_and_rerunnable(): void
    {
        $this->assertScenarioCreatesExpectedWorkload('MIDDLE', 270, 9, 14, 80, 80);

        $before = $this->operationalCounts();
        $exitCode = Artisan::call('acceptance:seed-scheduling-scenario', ['scenario' => 'MIDDLE']);

        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
        $this->assertSame($before, $this->operationalCounts());
    }

    public function test_max_scenario_is_executable_and_reports_input_readiness_without_claiming_solver_feasibility(): void
    {
        $output = $this->assertScenarioCreatesExpectedWorkload('MAX', 600, 20, 26, 80, 178);
        $this->assertStringContainsString('readiness=PASS', $output);
        $this->assertStringContainsString('solver_feasibility=NOT_EVALUATED', $output);
        $this->assertStringContainsString('solver_optimality=NOT_EVALUATED', $output);
    }

    public function test_scenario_inspection_is_read_only_and_conflicting_scenario_selection_fails_closed(): void
    {
        $before = $this->operationalCounts();
        $inspectionExitCode = Artisan::call('acceptance:seed-scheduling-scenario', [
            'scenario' => 'MAX',
            '--check' => true,
        ]);
        $inspectionOutput = Artisan::output();

        $this->assertSame(Command::FAILURE, $inspectionExitCode, $inspectionOutput);
        $this->assertStringContainsString('outcome=inspection_only', $inspectionOutput);
        $this->assertStringContainsString('scenario=MAX', $inspectionOutput);
        $this->assertStringContainsString('target_students=600', $inspectionOutput);
        $this->assertSame($before, $this->operationalCounts());

        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-scheduling-scenario', ['scenario' => 'MIDDLE']));
        $middleCounts = $this->operationalCounts();
        $conflictExitCode = Artisan::call('acceptance:seed-scheduling-scenario', ['scenario' => 'MAX']);

        $this->assertSame(Command::FAILURE, $conflictExitCode);
        $this->assertStringContainsString('partial, conflicting, or another scenario', Artisan::output());
        $this->assertSame($middleCounts, $this->operationalCounts());
    }

    public function test_scenario_rerun_fails_closed_after_an_operator_edits_a_manifest_source_record(): void
    {
        $this->assertSame(
            Command::SUCCESS,
            Artisan::call('acceptance:seed-scheduling-scenario', ['scenario' => 'MIDDLE']),
        );
        $group = SectionDeliveryGroup::query()->where('name', 'DIT-1A')->firstOrFail();
        $group->update(['name' => 'Edited Cohort']);
        $before = $this->operationalCounts();

        $exitCode = Artisan::call('acceptance:seed-scheduling-scenario', ['scenario' => 'MIDDLE']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial, conflicting, or another scenario', Artisan::output());
        $this->assertSame('Edited Cohort', $group->fresh()?->name);
        $this->assertSame($before, $this->operationalCounts());
    }

    private function assertScenarioCreatesExpectedWorkload(
        string $scenario,
        int $students,
        int $cohorts,
        int $faculty,
        int $offerings,
        int $demands,
    ): string {
        $exitCode = Artisan::call('acceptance:seed-scheduling-scenario', ['scenario' => $scenario]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=created', $output);
        $this->assertStringContainsString("scenario={$scenario}", $output);
        $this->assertStringContainsString("students={$students}", $output);
        $this->assertStringContainsString("cohorts={$cohorts}", $output);
        $this->assertStringContainsString("faculty={$faculty}", $output);
        $this->assertStringContainsString("synthetic_scheduling_faculty={$faculty}", $output);
        $this->assertStringContainsString('total_teaching_units=', $output);
        $this->assertStringContainsString('arithmetic_faculty_lower_bound=', $output);
        $this->assertStringContainsString('faculty_availability_assumption=FULL_OPERATING_GRID', $output);
        $this->assertStringContainsString('bounded_faculty_readiness=PASS', $output);
        $this->assertStringContainsString('unassignable_workloads=[]', $output);
        $this->assertStringContainsString("term_offerings={$offerings}", $output);
        $this->assertStringContainsString("scheduling_demands={$demands}", $output);
        $this->assertStringContainsString('operating_grid=MON-SAT 07:00-21:00 Asia/Manila', $output);
        $this->assertSame($students, StudentProfile::query()->count());
        $this->assertSame($offerings, TermOffering::query()->count());
        $this->assertSame($demands, Section::query()->count());
        $this->assertSame($demands, SectionDeliveryGroup::query()->count());
        $this->assertSame($demands, SchedulingDemand::query()->count());
        $this->assertSame(
            $faculty,
            User::role(User::StaffRoleFaculty)->count(),
        );
        $this->assertSame(
            $cohorts,
            SectionDeliveryGroup::query()->distinct()->count('name'),
        );
        $this->assertSame(
            $demands,
            SchedulingDemand::query()
                ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
                ->count(),
        );
        $term = Term::query()->sole();
        $this->assertSame('21:00:00', $term->scheduling_day_ends_at);

        return $output;
    }

    /** @return array<string, int> */
    private function operationalCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'students' => StudentProfile::query()->count(),
            'programs' => Program::query()->count(),
            'courses' => Course::query()->count(),
            'curriculum_entries' => CurriculumEntry::query()->count(),
            'offerings' => TermOffering::query()->count(),
            'sections' => Section::query()->count(),
            'groups' => SectionDeliveryGroup::query()->count(),
            'demands' => SchedulingDemand::query()->count(),
        ];
    }

    private function clearOperationalDataInsideTestTransaction(): void
    {
        SchedulingDemand::query()->delete();
        SectionDeliveryGroup::query()->delete();
        Section::query()->delete();
        TermOffering::query()->delete();
        FeeRule::query()->delete();
        FacultyQualification::query()->delete();
        FacultyTermLoadOverride::query()->delete();
        CalendarEvent::query()->delete();
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
    }
}
