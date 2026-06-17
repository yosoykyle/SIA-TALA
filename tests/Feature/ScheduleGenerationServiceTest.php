<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleGenerationService;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduleGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_creates_snapshot_and_dispatches_solver_job_after_commit(): void
    {
        Queue::fake();

        [$term, $registrar] = $this->readyTermAndRegistrar();
        $runId = null;

        DB::transaction(function () use ($term, $registrar, &$runId): void {
            $run = app(ScheduleGenerationService::class)->generate($term, $registrar);
            $runId = $run->id;

            $this->assertSame(ScheduleGenerationRun::StatusDraft, $run->status);
            $this->assertNotNull($run->solver_input_snapshot);
            $this->assertNotNull($run->solver_input_hash);
            $this->assertSame('queued', $run->constraint_summary['solver_dispatch']['status']);

            Queue::assertPushed(ScheduleSolverDispatchJob::class, function (ScheduleSolverDispatchJob $job) use ($runId): bool {
                return $job->scheduleGenerationRunId === $runId
                    && $job->afterCommit === true;
            });
        });

        Queue::assertPushed(ScheduleSolverDispatchJob::class, function (ScheduleSolverDispatchJob $job) use ($runId): bool {
            return $job->scheduleGenerationRunId === $runId
                && $job->afterCommit === true;
        });
    }

    public function test_generate_requires_manage_schedules_permission(): void
    {
        [$term] = $this->readyTermAndRegistrar();
        $actor = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(ScheduleGenerationService::class)->generate($term, $actor);
    }

    /**
     * @return array{Term, User}
     */
    private function readyTermAndRegistrar(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-schedules'));

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

        Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'room' => 'R-101',
            'modality' => 'on_site',
        ]);
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

        return [$term, $registrar];
    }
}
