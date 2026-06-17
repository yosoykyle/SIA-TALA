<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\PreUatScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PreUatScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_uat_scenario_seeder_creates_minimum_working_dataset(): void
    {
        $this->seed(PreUatScenarioSeeder::class);

        $this->assertDatabaseHas('terms', [
            'term_name' => 'Pre-UAT 1st Semester AY 2026-2027',
        ]);
        $this->assertDatabaseHas('programs', [
            'code' => 'BSIT',
        ]);
        $this->assertDatabaseHas('sections', [
            'name' => 'BSIT-1A',
            'max_seats' => 30,
            'modality' => 'on_site',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'student_id' => 'TALA-2026-0001',
            'education_level' => 'college',
        ]);
        $this->assertDatabaseHas('payment_attempts', [
            'provider_event_id' => 'evt_pre_uat_payment_0001',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('faq_entries', [
            'question' => 'How do I request a document during Pre-UAT?',
            'is_published' => true,
        ]);

        $this->assertSame(2, $this->tableCount('subjects'));
        $this->assertSame(2, $this->tableCount('curriculum_subjects'));
        $this->assertSame(1, $this->tableCount('curriculum_readiness_scopes'));
        $this->assertSame(2, $this->tableCount('faculty_subject_eligibilities'));
        $this->assertSame(2, $this->tableCount('faculty_availability_windows'));
        $this->assertSame(2, $this->tableCount('enrollment_subjects'));
        $this->assertSame(2, $this->tableCount('ledger_entries'));
        $this->assertSame(0, $this->tableCount('section_meetings'));
    }

    public function test_pre_uat_scenario_seeder_is_idempotent(): void
    {
        $this->seed(PreUatScenarioSeeder::class);

        $countsAfterFirstRun = $this->scenarioCounts();

        $this->seed(PreUatScenarioSeeder::class);

        $this->assertSame($countsAfterFirstRun, $this->scenarioCounts());
    }

    public function test_seeded_scheduling_inputs_are_ready_for_solver_snapshot(): void
    {
        $this->seed(PreUatScenarioSeeder::class);

        $term = Term::query()
            ->where('term_name', 'Pre-UAT 1st Semester AY 2026-2027')
            ->firstOrFail();
        $registrar = User::query()
            ->where('email', 'registrar@tala.edu')
            ->firstOrFail();

        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);

        $this->assertTrue($readiness['is_ready']);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusDraft,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
        ]);

        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);

        $this->assertCount(1, $snapshot['sections']);
        $this->assertCount(2, $snapshot['curriculum_subject_demand']);
        $this->assertCount(2, $snapshot['faculty_eligibility']);
        $this->assertNotEmpty($snapshot['faculty_availability']);
        $this->assertNotEmpty($snapshot['rooms_catalog']);
    }

    public function test_pre_uat_scenario_seeder_is_blocked_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PreUatScenarioSeeder is local/UAT-only');

        app(PreUatScenarioSeeder::class)->run();
    }

    private function tableCount(string $table): int
    {
        return (int) $this->app['db']->table($table)->count();
    }

    /**
     * @return array<string, int>
     */
    private function scenarioCounts(): array
    {
        return collect([
            'academic_years',
            'programs',
            'subjects',
            'curriculums',
            'curriculum_subjects',
            'curriculum_readiness_scopes',
            'terms',
            'sections',
            'faculty_subject_eligibilities',
            'faculty_availability_periods',
            'faculty_availability_submissions',
            'faculty_availability_windows',
            'student_profiles',
            'enrollments',
            'enrollment_subjects',
            'fee_templates',
            'ledger_entries',
            'payment_attempts',
            'payments',
            'document_uploads',
            'document_requests',
            'grades',
            'grade_corrections',
            'service_requests',
            'faq_entries',
            'section_meetings',
        ])
            ->mapWithKeys(fn (string $table): array => [$table => $this->tableCount($table)])
            ->all();
    }
}
