<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Resources\FacultyAvailabilityPeriods\Pages\CreateFacultyAvailabilityPeriod;
use App\Filament\Resources\FacultyAvailabilitySubmissions\Pages\CreateFacultyAvailabilitySubmission;
use App\Filament\Resources\FacultyAvailabilitySubmissions\Pages\ListFacultyAvailabilitySubmissions;
use App\Filament\Resources\FacultySubjectEligibilities\Pages\CreateFacultySubjectEligibility;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\DraftRowsRelationManager;
use App\Filament\Resources\Sections\Pages\CreateSection;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SchedulingFilamentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_can_be_driven_from_filament_surfaces_until_commit(): void
    {
        Queue::fake();

        $registrar = $this->staffUser(User::StaffRoleRegistrar, [
            'manage-schedules',
            'manage-faculty-subject-eligibilities',
            'review-lock-faculty-availability',
        ]);
        $faculty = $this->staffUser(User::StaffRoleFaculty, [
            'submit-faculty-availability',
        ]);
        [$term, $program, $curriculum, $subjects] = $this->readySchedulingPrerequisites();

        $this->actingAs($registrar);

        Livewire::test(CreateSection::class)
            ->fillForm([
                'term_id' => $term->id,
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'year_level' => '1st Year',
                'curriculum_period' => '1st Semester',
                'name' => 'BSIT 1A',
                'modality' => 'on_site',
                'room' => 'R-101',
                'max_seats' => 30,
                'enrolled_count' => 24,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $section = Section::query()->where('name', 'BSIT 1A')->firstOrFail();

        foreach ($subjects as $subject) {
            Livewire::test(CreateFacultySubjectEligibility::class)
                ->fillForm([
                    'faculty_id' => $faculty->id,
                    'subject_id' => $subject->id,
                    'term_id' => $term->id,
                    'status' => FacultySubjectEligibility::StatusActive,
                    'priority' => null,
                    'max_weekly_hours' => null,
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(2, FacultySubjectEligibility::query()->where('faculty_id', $faculty->id)->count());

        Livewire::test(CreateFacultyAvailabilityPeriod::class)
            ->fillForm([
                'term_id' => $term->id,
                'opens_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'closes_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $period = FacultyAvailabilityPeriod::query()->where('term_id', $term->id)->firstOrFail();

        $this->actingAs($faculty);

        Livewire::test(CreateFacultyAvailabilitySubmission::class)
            ->fillForm([
                'availability_period_id' => $period->id,
                'windows' => [
                    [
                        'day_of_week' => 1,
                        'starts_at' => '08:00:00',
                        'ends_at' => '12:00:00',
                        'notes' => 'Available for schedule generation.',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $submission = FacultyAvailabilitySubmission::query()
            ->where('faculty_id', $faculty->id)
            ->where('term_id', $term->id)
            ->firstOrFail();

        $this->actingAs($registrar);

        Livewire::test(ListFacultyAvailabilitySubmissions::class)
            ->callAction(TestAction::make('lockAvailability')->table($submission))
            ->assertHasNoFormErrors();

        $this->assertSame(FacultyAvailabilitySubmission::StatusLocked, $submission->refresh()->status);

        $this->app->instance(SchedulingSolverClient::class, new FilamentWorkflowSchedulingSolverClient);

        Livewire::test(ListScheduleGenerationRuns::class)
            ->callAction('generateSchedule', data: [
                'term_id' => $term->id,
            ])
            ->assertHasNoFormErrors();

        $run = ScheduleGenerationRun::query()->where('term_id', $term->id)->firstOrFail();

        Queue::assertPushed(
            ScheduleSolverDispatchJob::class,
            fn (ScheduleSolverDispatchJob $job): bool => $job->scheduleGenerationRunId === $run->id
        );

        app(ScheduleSolverDispatchJob::class, [
            'scheduleGenerationRunId' => $run->id,
        ])->handle(
            app(ScheduleSolverSnapshotService::class),
            app(SchedulingSolverClient::class),
            app(ScheduleCloudResultIngestor::class),
        );

        $run->refresh();
        $draftRows = ScheduleDraftRow::query()
            ->where('generation_run_id', $run->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame(2, $draftRows->count());
        $this->assertSame(2, $draftRows->where('status', ScheduleDraftRow::StatusOk)->count());

        Livewire::test(DraftRowsRelationManager::class, [
            'ownerRecord' => $run,
            'pageClass' => ViewScheduleGenerationRun::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords($draftRows);

        Livewire::test(ListScheduleGenerationRuns::class)
            ->callAction(TestAction::make('commitSchedule')->table($run))
            ->assertHasNoFormErrors();

        $run->refresh();

        $this->assertSame(ScheduleGenerationRun::StatusCommitted, $run->status);
        $this->assertSame($registrar->id, $run->committed_by);
        $this->assertSame(2, SectionMeeting::query()->where('schedule_generation_run_id', $run->id)->count());

        foreach ($subjects as $subject) {
            $this->assertDatabaseHas('section_teacher', [
                'section_id' => $section->id,
                'user_id' => $faculty->id,
                'subject_id' => $subject->id,
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::staffRoleNames() as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    /**
     * @return array{0: Term, 1: Program, 2: Curriculum, 3: list<Subject>}
     */
    private function readySchedulingPrerequisites(): array
    {
        $term = Term::factory()->create([
            'term_name' => '1st Semester AY 2026',
            'term_start_date' => now()->addWeeks(2)->toDateString(),
            'term_end_date' => now()->addMonths(5)->toDateString(),
            'class_start_date' => now()->addWeeks(2)->toDateString(),
            'class_end_date' => now()->addMonths(5)->toDateString(),
            'scheduling_starts_at' => now()->addDays(2),
        ]);
        $program = Program::factory()->create([
            'name' => 'Bachelor of Science in Information Technology',
            'code' => 'BSIT',
        ]);
        $curriculum = Curriculum::factory()->create([
            'program_id' => $program->id,
            'version_name' => 'BSIT 2026',
            'effective_year' => '2026',
            'is_active' => true,
        ]);
        Room::factory()->create([
            'code' => 'R-101',
            'name' => 'Workflow Room 101',
            'capacity' => Section::MaxRescueSeats,
        ]);
        $subjects = [
            Subject::factory()->create([
                'code' => 'IT101',
                'description' => 'Introduction to Computing',
                'lec_hours' => '3.00',
                'units' => '3.00',
            ]),
            Subject::factory()->create([
                'code' => 'MATH101',
                'description' => 'College Algebra',
                'lec_hours' => '3.00',
                'units' => '3.00',
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

        return [$term, $program, $curriculum, $subjects];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);
        $user->givePermissionTo($permissions);

        return $user;
    }
}

final class FilamentWorkflowSchedulingSolverClient implements SchedulingSolverClient
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
                $facultyId = $this->facultyIdForSubject($snapshot, (int) $demand['subject_id']);
                $startHour = 8 + $index;

                return [
                    'section_id' => (int) $demand['section_id'],
                    'subject_id' => (int) $demand['subject_id'],
                    'faculty_id' => $facultyId,
                    'room' => $section['fixed_room'] ?? null,
                    'day_of_week' => 1,
                    'starts_at' => sprintf('%02d:00:00', $startHour),
                    'ends_at' => sprintf('%02d:00:00', $startHour + 1),
                    'modality' => $section['modality'] ?? 'on_site',
                    'status' => ScheduleDraftRow::StatusOk,
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
