<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\DeliveryPattern;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SchedulingEndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_can_generate_ingest_review_and_publish_schedule_from_locked_faculty_availability(): void
    {
        Queue::fake();

        $registrar = $this->staffWithPermissions([
            'manage-schedules',
            'review-lock-faculty-availability',
            'manage-faculty-subject-eligibilities',
        ]);
        $faculty = $this->staffWithPermissions(['submit-faculty-availability']);
        [$term, $section, $subjects] = $this->readySectionWithCurriculumDemand();

        foreach ($subjects as $subject) {
            FacultySubjectEligibility::factory()->create([
                'faculty_id' => $faculty->id,
                'subject_id' => $subject->id,
                'term_id' => $term->id,
                'approved_by' => $registrar->id,
            ]);
        }

        $periodData = app(FacultyAvailabilityService::class)->preparePeriodData([
            'term_id' => $term->id,
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addDay(),
        ], $registrar);
        $period = FacultyAvailabilityPeriod::query()->create($periodData);
        $submission = app(FacultyAvailabilityService::class)->submitAvailability([
            'availability_period_id' => $period->id,
            'windows' => [
                [
                    'day_of_week' => 1,
                    'starts_at' => '08:00:00',
                    'ends_at' => '12:00:00',
                    'notes' => 'Available for generated schedule QA.',
                ],
            ],
        ], $faculty);
        $lockedSubmission = app(FacultyAvailabilityService::class)->lockSubmission($submission, $registrar);

        $this->app->instance(SchedulingSolverClient::class, new SnapshotDrivenSchedulingSolverClient);

        $run = app(ScheduleGenerationService::class)->generate($term, $registrar);

        Queue::assertPushed(ScheduleSolverDispatchJob::class, fn (ScheduleSolverDispatchJob $job): bool => $job->scheduleGenerationRunId === $run->id
            && $job->afterCommit === true);

        app(ScheduleSolverDispatchJob::class, [
            'scheduleGenerationRunId' => $run->id,
        ])->handle(
            app(ScheduleSolverSnapshotService::class),
            app(SchedulingSolverClient::class),
            app(ScheduleCloudResultIngestor::class),
        );

        $run->refresh();

        $this->assertSame(FacultyAvailabilitySubmission::StatusLocked, $lockedSubmission->status);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame('completed', $run->constraint_summary['solver_dispatch']['status']);
        $this->assertSame('ingested', $run->constraint_summary['solver_ingestion']['status']);
        $this->assertSame(2, $run->constraint_summary['solver_ingestion']['ok_count']);
        $this->assertSame(0, $run->constraint_summary['solver_ingestion']['conflict_count']);
        $this->assertSame(2, ScheduleDraftRow::query()->where('generation_run_id', $run->id)->where('status', ScheduleDraftRow::StatusOk)->count());

        $publishedRun = app(SchedulePublishService::class)->publish($run, $registrar, 'Registrar reviewed generated schedule.');

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $publishedRun->status);
        $this->assertSame($registrar->id, $publishedRun->committed_by);
        $this->assertSame($registrar->id, $publishedRun->published_by);
        $this->assertSame(2, SectionMeeting::query()->where('schedule_generation_run_id', $run->id)->count());

        foreach ($subjects as $subject) {
            $this->assertDatabaseHas('section_teacher', [
                'section_id' => $section->id,
                'user_id' => $faculty->id,
                'subject_id' => $subject->id,
            ]);
        }
    }

    /**
     * @return array{0: Term, 1: Section, 2: list<Subject>}
     */
    private function readySectionWithCurriculumDemand(): array
    {
        $term = Term::factory()->create([
            'term_name' => '1st Semester AY 2026',
            'term_start_date' => now()->addWeeks(2)->toDateString(),
            'term_end_date' => now()->addMonths(5)->toDateString(),
            'scheduling_starts_at' => now()->addDays(2),
        ]);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $curriculum = Curriculum::factory()->create([
            'program_id' => $program->id,
            'version_name' => 'BSIT 2026',
        ]);
        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'name' => 'BSIT 1A',
            'room' => 'R-101',
            'max_seats' => 30,
            'enrolled_count' => 24,
            'modality' => 'on_site',
        ]);
        $this->deliveryGroup($section);
        $subjects = [
            Subject::factory()->create([
                'code' => 'IT101',
                'units' => '3.00',
                'lec_hours' => '3.00',
            ]),
            Subject::factory()->create([
                'code' => 'MATH101',
                'units' => '3.00',
                'lec_hours' => '3.00',
            ]),
        ];

        foreach ($subjects as $index => $subject) {
            CurriculumSubject::factory()->create([
                'curriculum_id' => $curriculum->id,
                'subject_id' => $subject->id,
                'year_level' => '1st Year',
                'semester' => '1st Semester',
                'sort_order' => $index + 1,
            ]);
        }
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

        return [$term, $section, $subjects];
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

    /**
     * @param  list<string>  $permissions
     */
    private function staffWithPermissions(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }
}

final class SnapshotDrivenSchedulingSolverClient implements SchedulingSolverClient
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function solve(array $snapshot): array
    {
        $draftRows = collect($snapshot['curriculum_subject_demand'] ?? [])
            ->values()
            ->map(function (array $demand, int $index) use ($snapshot): array {
                $section = collect($snapshot['sections'] ?? [])
                    ->first(fn (array $section): bool => (int) $section['section_id'] === (int) $demand['section_id']);
                $deliveryGroup = collect($snapshot['section_delivery_groups'] ?? [])
                    ->first(fn (array $group): bool => (int) $group['section_delivery_group_id'] === (int) $demand['section_delivery_group_id']);
                $facultyId = $this->facultyIdForSubject($snapshot, (int) $demand['subject_id']);
                $startHour = 8 + $index;

                return [
                    'section_id' => (int) $demand['section_id'],
                    'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                    'subject_id' => (int) $demand['subject_id'],
                    'faculty_id' => $facultyId,
                    'room' => $deliveryGroup['fixed_room'] ?? $section['fixed_room'] ?? null,
                    'day_of_week' => 1,
                    'starts_at' => sprintf('%02d:00:00', $startHour),
                    'ends_at' => sprintf('%02d:00:00', $startHour + 1),
                    'modality' => $deliveryGroup['modality'] ?? $section['modality'] ?? 'on_site',
                    'status' => 'ok',
                ];
            })
            ->all();

        return [
            'solver_status' => 'test_optimal',
            'assigned_count' => count($draftRows),
            'unassigned_count' => 0,
            'hard_violation_count' => 0,
            'warning_count' => 0,
            'timeout' => false,
            'objective_score' => 1000,
            'solve_time_ms' => 1,
            'draft_rows' => $draftRows,
        ];
    }

    /**
     * @return array{status:int, body:string}
     */
    public function probe(): array
    {
        return [
            'status' => 200,
            'body' => 'test solver',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function facultyIdForSubject(array $snapshot, int $subjectId): ?int
    {
        $eligibility = collect($snapshot['faculty_eligibility'] ?? [])
            ->first(fn (array $eligibility): bool => (int) $eligibility['subject_id'] === $subjectId);

        return is_array($eligibility) ? (int) $eligibility['faculty_id'] : null;
    }
}
