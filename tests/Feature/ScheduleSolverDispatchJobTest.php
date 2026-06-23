<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\DeliveryPattern;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ScheduleSolverDispatchJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_solver_client_with_snapshot_and_records_result_summary(): void
    {
        [$run] = $this->readyRun();
        $client = new FakeDispatchSchedulingSolverClient([
            'solver_status' => 'local_stub',
            'assigned_count' => 0,
            'unassigned_count' => 0,
            'hard_violation_count' => 0,
            'warning_count' => 0,
            'timeout' => false,
            'solve_time_ms' => 12,
            'draft_rows' => [],
        ]);

        $this->app->instance(SchedulingSolverClient::class, $client);

        app(ScheduleSolverDispatchJob::class, [
            'scheduleGenerationRunId' => $run->id,
        ])->handle(
            app(ScheduleSolverSnapshotService::class),
            app(SchedulingSolverClient::class),
            app(ScheduleCloudResultIngestor::class),
        );

        $run->refresh();

        $this->assertSame($run->id, $client->snapshot['run_metadata']['run_id']);
        $this->assertSame('completed', $run->constraint_summary['solver_dispatch']['status']);
        $this->assertSame('local_stub', $run->constraint_summary['solver_dispatch']['result_summary']['solver_status']);
        $this->assertSame(0, $run->constraint_summary['solver_dispatch']['result_summary']['draft_row_count']);
        $this->assertSame(0, $run->constraint_summary['solver_dispatch']['result_summary']['hard_violation_count']);
        $this->assertSame('blocked', $run->constraint_summary['solver_ingestion']['status']);
        $this->assertSame('missing_draft_rows', $run->constraint_summary['solver_ingestion']['blocked_reason']);
        $this->assertSame(0, $run->constraint_summary['solver_dispatch']['ingestion_summary']['draft_row_count']);
    }

    public function test_job_records_failure_summary_then_rethrows(): void
    {
        [$run] = $this->readyRun();

        $this->app->instance(SchedulingSolverClient::class, new FailingDispatchSchedulingSolverClient);

        try {
            app(ScheduleSolverDispatchJob::class, [
                'scheduleGenerationRunId' => $run->id,
            ])->handle(
                app(ScheduleSolverSnapshotService::class),
                app(SchedulingSolverClient::class),
                app(ScheduleCloudResultIngestor::class),
            );

            $this->fail('Expected solver dispatch failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('solver unavailable', $exception->getMessage());
        }

        $run->refresh();

        $this->assertSame('failed', $run->constraint_summary['solver_dispatch']['status']);
        $this->assertSame(RuntimeException::class, $run->constraint_summary['solver_dispatch']['exception']);
        $this->assertSame('solver unavailable', $run->constraint_summary['solver_dispatch']['message']);
    }

    /**
     * @return array{ScheduleGenerationRun}
     */
    private function readyRun(): array
    {
        $registrar = User::factory()->create();
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $curriculum = Curriculum::factory()->create([
            'program_id' => $program->id,
        ]);
        $subject = Subject::factory()->create();
        $faculty = User::factory()->create();

        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => '1st Year',
            'semester' => '1st Semester',
        ]);
        CurriculumReadinessScope::query()->updateOrCreate(
            [
                'curriculum_id' => $curriculum->id,
                'year_level' => '1st Year',
                'curriculum_period' => '1st Semester',
            ],
            [
                'status' => CurriculumReadinessScope::StatusReadyForScheduling,
                'last_transition_at' => now(),
                'last_blockers' => [],
            ],
        );

        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);
        $this->deliveryGroup($section);
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'approved_by' => $registrar->id,
        ]);
        $period = FacultyAvailabilityPeriod::factory()->create([
            'term_id' => $term->id,
            'created_by' => $registrar->id,
            'status' => FacultyAvailabilityPeriod::StatusLocked,
            'locked_at' => now(),
        ]);
        $submission = FacultyAvailabilitySubmission::factory()->create([
            'term_id' => $term->id,
            'availability_period_id' => $period->id,
            'faculty_id' => $faculty->id,
            'status' => FacultyAvailabilitySubmission::StatusLocked,
            'locked_at' => now(),
            'approved_by' => $registrar->id,
            'approved_at' => now(),
        ]);
        FacultyAvailabilityWindow::factory()->create([
            'submission_id' => $submission->id,
        ]);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusDraft,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'constraint_summary' => [
                'solver_dispatch' => [
                    'status' => 'queued',
                ],
            ],
        ]);

        return [$run];
    }

    private function deliveryGroup(Section $section): SectionDeliveryGroup
    {
        $pattern = DeliveryPattern::factory()->create([
            'modality' => 'on_site',
            'default_room_required' => true,
        ]);

        return SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'delivery_pattern_id' => $pattern->id,
            'name' => 'Primary F2F',
            'modality' => 'on_site',
            'capacity' => $section->max_seats,
            'assigned_count' => 0,
            'room_required' => true,
            'room' => 'R-101',
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
    }
}

final class FakeDispatchSchedulingSolverClient implements SchedulingSolverClient
{
    /**
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(private readonly array $result) {}

    public function solve(array $snapshot): array
    {
        $this->snapshot = $snapshot;

        return $this->result;
    }

    public function probe(): array
    {
        return [
            'status' => 200,
            'body' => 'fake',
        ];
    }
}

final class FailingDispatchSchedulingSolverClient implements SchedulingSolverClient
{
    public function solve(array $snapshot): array
    {
        throw new RuntimeException('solver unavailable');
    }

    public function probe(): array
    {
        return [
            'status' => 500,
            'body' => 'fake failure',
        ];
    }
}
