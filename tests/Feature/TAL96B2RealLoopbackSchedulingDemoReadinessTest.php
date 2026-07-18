<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentPlacementService;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Student\Pages\ScheduleView;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class TAL96B2RealLoopbackSchedulingDemoReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private const ContractVersion = 'tal94-demand-v2';

    private const SolverVersion = 'cloud-cp-sat-tal94-demand-v2';

    public function test_client_baseline_completes_the_real_solver_publication_and_cross_role_projections(): void
    {
        $mode = trim((string) getenv('TALA_96B2_ACCEPTANCE_MODE'));

        if ($mode === '' && (string) getenv('TALA_96B2_REAL_LOOPBACK') === '1') {
            $mode = 'local_http';
        }

        if (! in_array($mode, ['local_http', 'cloud_run'], true)) {
            $this->markTestSkipped('Set TALA_96B2_ACCEPTANCE_MODE to local_http or cloud_run for guarded real-service acceptance.');
        }

        $solverUrl = $mode === 'local_http'
            ? (trim((string) getenv('TALA_96B2_SOLVER_URL')) ?: 'http://127.0.0.1:8080')
            : $this->requiredSetting('TALA_96B2_SOLVER_URL');
        $audience = $mode === 'cloud_run'
            ? $this->requiredSetting('TALA_96B2_SOLVER_AUDIENCE')
            : null;
        $credentialsPath = $mode === 'cloud_run'
            ? $this->requiredSetting('TALA_96B2_SOLVER_CREDENTIALS')
            : null;
        $cloudRevision = $mode === 'cloud_run'
            ? $this->requiredSetting('TALA_96B2_CLOUD_REVISION')
            : null;
        $acceptanceRepetitions = $this->boundedIntegerSetting(
            'TALA_96B2_ACCEPTANCE_REPETITIONS',
            default: 3,
            minimum: 1,
            maximum: 3,
        );
        $expectedWorkerCount = $this->boundedIntegerSetting(
            'TALA_96B2_EXPECTED_WORKER_COUNT',
            default: 1,
            minimum: 1,
            maximum: 4,
        );

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);
        $this->assertSame(0, ScheduleGenerationRun::query()->count(), 'Clear scheduling demo runs before real-loopback acceptance.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Clear the database queue before real-loopback acceptance.');

        if ($credentialsPath !== null) {
            $this->assertFileExists($credentialsPath);
        }

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());

        config()->set([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'tala_integrations.scheduling_solver.driver' => $mode,
            'tala_integrations.scheduling_solver.url' => $solverUrl,
            'tala_integrations.scheduling_solver.audience' => $audience,
            'tala_integrations.scheduling_solver.credentials_path' => $credentialsPath,
            'tala_integrations.scheduling_solver.timeout_seconds' => 300,
            'tala_integrations.scheduling_solver.connect_timeout_seconds' => 10,
        ]);
        $this->app->forgetInstance(SchedulingSolverClient::class);
        Queue::fake();
        Mail::fake();

        $solverClient = app(SchedulingSolverClient::class);
        $probe = $solverClient->probe();
        $this->assertSame(200, $probe['status']);
        $this->assertStringContainsString('tal94-demand-v2', $probe['body']);

        $term = Term::query()->where('type', Term::TypeSecondSemester)->sole();
        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
        $expectedCoverage = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->orderBy('id')
            ->get()
            ->flatMap(fn (SchedulingDemand $demand): array => collect(range(1, $demand->meeting_count))
                ->map(fn (int $sequence): string => $demand->id.':'.$sequence)
                ->all())
            ->values()
            ->all();

        $run = app(ScheduleGenerationService::class)->generate($term, $registrar);
        $this->assertSame(ScheduleGenerationRun::StatusQueued, $run->status);
        Queue::assertPushed(ScheduleSolverDispatchJob::class);

        $snapshot = $run->getAttribute('input_snapshot');
        $this->assertIsArray($snapshot);
        $encodedSnapshot = json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        $snapshotHash = hash('sha256', $encodedSnapshot);
        $results = [];

        foreach (range(1, $acceptanceRepetitions) as $iteration) {
            $result = $solverClient->solve($snapshot);
            $this->assertContains($result['solver_status'], ['optimal', 'feasible'], "Representative run {$iteration} was not usable.");
            $this->assertSame(54, $result['assigned_count']);
            $this->assertSame(0, $result['unassigned_count']);
            $this->assertSame(0, $result['hard_violation_count']);
            $this->assertSame($expectedWorkerCount, data_get($result, 'solver_statistics.worker_count'));
            $this->assertSame(20260718, data_get($result, 'solver_statistics.random_seed'));
            $this->assertSame(54, data_get($result, 'solver_statistics.input_demand_count'));
            $validation = app(ScheduleAssignmentValidationService::class)->validate($run, $result);
            $this->assertTrue(
                $validation->passes(),
                json_encode($validation->findings(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
            $results[] = $result;
        }

        $solutionHashes = collect($results)
            ->map(fn (array $result): string => hash('sha256', json_encode([
                'solver_status' => $result['solver_status'],
                'assignments' => $result['assignments'],
                'objective_score' => $result['objective_score'],
                'objective_details' => $result['objective_details'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)))
            ->unique()
            ->values();

        $dispatchClient = new class($results[0]) implements SchedulingSolverClient
        {
            /** @var list<array<string, mixed>> */
            public array $receivedSnapshots = [];

            /** @param array<string, mixed> $result */
            public function __construct(private readonly array $result) {}

            /** @param array<string, mixed> $snapshot */
            public function solve(array $snapshot): array
            {
                $this->receivedSnapshots[] = $snapshot;

                return $this->result;
            }

            public function probe(): array
            {
                return ['status' => 200, 'body' => 'acceptance-test'];
            }
        };

        (new ScheduleSolverDispatchJob((int) $run->id))->handle(
            app(ScheduleSolverSnapshotService::class),
            $dispatchClient,
            app(ScheduleCloudResultIngestor::class),
        );
        $this->assertSame([$snapshot], $dispatchClient->receivedSnapshots);

        $run->refresh();
        $candidates = $run->candidateRows()
            ->orderBy('scheduling_demand_id')
            ->orderBy('meeting_sequence')
            ->get();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame(self::SolverVersion, $run->solver_version);
        $this->assertSame(self::ContractVersion, $run->model_version);
        $this->assertSame(self::ContractVersion, data_get($run->input_snapshot, 'contract_version'));
        $this->assertSame(
            $expectedCoverage,
            $candidates->map(fn (CandidateScheduleRow $row): string => $row->scheduling_demand_id.':'.$row->meeting_sequence)->all(),
        );
        $this->assertTrue($candidates->every(
            fn (CandidateScheduleRow $row): bool => in_array($row->status, [CandidateScheduleRow::StatusOk, CandidateScheduleRow::StatusWarning], true)
                && blank($row->violations),
        ));
        $this->assertSame(0, data_get($run->diagnostics, 'solver_result.summary.unassigned_count'));
        $this->assertSame(0, data_get($run->diagnostics, 'solver_result.summary.hard_violation_count'));
        $this->assertEquals(
            $results[0]['solver_statistics'],
            data_get($run->diagnostics, 'solver_result.solver_statistics'),
        );
        $this->assertGreaterThan(0, $run->runtime_ms);

        $dispatchEvent = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeSolverDispatchAttempt)
            ->where('related_record_type', ScheduleGenerationRun::class)
            ->where('related_record_id', $run->id)
            ->sole();
        $this->assertSame(OperationalEvent::StatusProcessed, $dispatchEvent->status);

        $summary = $run->publicationSummary();
        $this->assertSame(0, $summary['conflicts']);
        $published = app(SchedulePublishService::class)->publish(
            $run,
            $registrar,
            $summary['warnings'] > 0 ? 'TAL-96B2 verified real-loopback acceptance.' : null,
        );
        $meetings = $published->sectionMeetings()
            ->with([
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                'schedulingDemand.courseComponent',
                'schedulingDemand.sectionDeliveryGroup.section',
                'faculty',
                'room',
            ])
            ->orderBy('id')
            ->get();

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
        $this->assertCount(count($expectedCoverage), $meetings);
        $this->assertTrue($meetings->every(
            fn (SectionMeeting $meeting): bool => $meeting->state === SectionMeeting::StateActive,
        ));

        $expectedCohortCounts = [
            'DTBM-1A' => 10,
            'DTBM-2A' => 9,
            'DIT-1A' => 8,
            'DIT-2A' => 8,
            'DTHM-1A' => 10,
            'DTHM-2A' => 9,
        ];
        $meetingsByCohort = $meetings->groupBy(
            fn (SectionMeeting $meeting): string => $this->cohortCode($meeting),
        );
        $actualCohortCounts = collect($expectedCohortCounts)
            ->mapWithKeys(fn (int $expected, string $cohort): array => [
                $cohort => $meetingsByCohort->get($cohort, collect())->count(),
            ])
            ->all();

        $this->assertSame($expectedCohortCounts, $actualCohortCounts);

        $registrarSchedule = Livewire::actingAs($registrar)
            ->test(ListSectionMeetings::class);
        $registrarSchedule->assertOk();
        $registrarSchedule->assertCountTableRecords(54);

        $facultyProjectionCounts = [];

        foreach ($meetings->groupBy('faculty_user_id') as $facultyUserId => $facultyMeetings) {
            $faculty = User::query()->findOrFail((int) $facultyUserId);
            $facultySchedule = Livewire::actingAs($faculty)
                ->test(FacultySchedule::class);

            $facultySchedule->assertOk();
            $facultySchedule->assertCountTableRecords($facultyMeetings->count());
            $facultySchedule->assertCanSeeTableRecords($facultyMeetings);
            $facultyProjectionCounts[] = [
                'faculty' => $faculty->name,
                'meeting_count' => $facultyMeetings->count(),
            ];
        }

        $this->assertCount(12, $facultyProjectionCounts);
        $this->assertSame(54, collect($facultyProjectionCounts)->sum('meeting_count'));

        $firstYearCohortCounts = [
            'DTBM-1A' => 10,
            'DIT-1A' => 8,
            'DTHM-1A' => 10,
        ];
        $studentProjectionCounts = [];

        foreach ($firstYearCohortCounts as $cohortCode => $expectedMeetingCount) {
            $profile = StudentProfile::query()
                ->with('user')
                ->where('student_number', 'like', $cohortCode.'-%')
                ->orderBy('student_number')
                ->firstOrFail();
            $student = $profile->user;
            $this->assertInstanceOf(User::class, $student);
            $this->assertNotNull($student->email_verified_at);

            $enrollment = Enrollment::factory()
                ->for($profile, 'studentProfile')
                ->for($term)
                ->create([
                    'status' => 'capacity_pending',
                    'student_type' => 'new',
                ]);
            $sections = $meetingsByCohort->get($cohortCode, collect())
                ->map(fn (SectionMeeting $meeting): Section => $meeting->schedulingDemand->sectionDeliveryGroup->section)
                ->filter(fn (?Section $section): bool => $section instanceof Section)
                ->unique('id')
                ->sortBy('code')
                ->values();

            $this->assertCount($expectedMeetingCount, $sections);

            foreach ($sections as $section) {
                app(EnrollmentPlacementService::class)->confirm($enrollment, $section->id, $registrar);
            }

            $bindings = StudentScheduleBinding::query()
                ->whereHas('courseEnrollment', fn ($query) => $query->where('enrollment_id', $enrollment->id))
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            $this->assertCount($expectedMeetingCount, $bindings);

            $studentSchedule = Livewire::actingAs($student)
                ->test(ScheduleView::class);
            $studentSchedule->assertOk();
            $studentSchedule->assertCountTableRecords($expectedMeetingCount);
            $studentSchedule->assertCanSeeTableRecords($bindings);
            $studentProjectionCounts[] = [
                'cohort' => $cohortCode,
                'student_number' => $profile->student_number,
                'meeting_count' => $bindings->count(),
            ];
        }

        $firstYearTimetables = collect(array_keys($firstYearCohortCounts))
            ->mapWithKeys(fn (string $cohortCode): array => [
                $cohortCode => $this->timetableRows($meetingsByCohort->get($cohortCode, collect())),
            ])
            ->all();

        if ((string) getenv('TALA_96B2_REPORT_EVIDENCE') === '1') {
            fwrite(STDOUT, PHP_EOL.'TAL96B2_EVIDENCE='.json_encode([
                'execution_mode' => $mode,
                'observed_at' => now()->toIso8601String(),
                'cloud_revision' => $cloudRevision,
                'cloud_profile' => $mode === 'cloud_run' ? [
                    'name' => 'B',
                    'vcpu' => 2,
                    'memory_gib' => 4,
                    'solver_workers' => $expectedWorkerCount,
                    'concurrency' => 1,
                    'search_limit_seconds' => 30,
                ] : null,
                'snapshot_sha256' => $snapshotHash,
                'solution_sha256_values' => $solutionHashes->all(),
                'unique_solution_count' => $solutionHashes->count(),
                'solver_version' => $results[0]['solver_version'],
                'model_version' => $results[0]['model_version'],
                'runs' => collect($results)
                    ->map(fn (array $result, int $index): array => [
                        'iteration' => $index + 1,
                        'solver_status' => $result['solver_status'],
                        'assigned_count' => $result['assigned_count'],
                        'unassigned_count' => $result['unassigned_count'],
                        'hard_violation_count' => $result['hard_violation_count'],
                        'objective_score' => $result['objective_score'],
                        'runtime_seconds' => $result['runtime_seconds'],
                        'solver_statistics' => $result['solver_statistics'],
                    ])
                    ->all(),
                'publication' => [
                    'candidate_count' => $candidates->count(),
                    'published_meeting_count' => $meetings->count(),
                    'cohort_meeting_counts' => $actualCohortCounts,
                ],
                'first_year_timetables' => $firstYearTimetables,
                'role_projections' => [
                    'registrar_official_meeting_count' => $meetings->count(),
                    'faculty_account_count' => count($facultyProjectionCounts),
                    'faculty_meeting_total' => collect($facultyProjectionCounts)->sum('meeting_count'),
                    'faculty_assignments' => $facultyProjectionCounts,
                    'student_schedules' => $studentProjectionCounts,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    private function cohortCode(SectionMeeting $meeting): string
    {
        $cohortCode = $meeting->schedulingDemand->sectionDeliveryGroup->name;
        $this->assertNotSame('', $cohortCode);

        return $cohortCode;
    }

    /**
     * @param  Collection<int, SectionMeeting>  $meetings
     * @return list<array{course:string,description:string,component:string,faculty:string,day:string,time:string,room:string,modality:string}>
     */
    private function timetableRows(Collection $meetings): array
    {
        return $meetings
            ->sortBy(fn (SectionMeeting $meeting): string => sprintf(
                '%02d-%s-%s',
                $meeting->day_of_week,
                $meeting->starts_at,
                $meeting->schedulingDemand->termOffering->curriculumEntry->courseSpecification->course->code,
            ))
            ->map(function (SectionMeeting $meeting): array {
                $componentType = $meeting->schedulingDemand->courseComponent->component_type;
                $modality = $meeting->modality;

                return [
                    'course' => $meeting->schedulingDemand->termOffering->curriculumEntry->courseSpecification->course->code,
                    'description' => $meeting->schedulingDemand->termOffering->curriculumEntry->courseSpecification->title,
                    'component' => CourseComponent::typeOptions()[$componentType] ?? str((string) $componentType)->headline()->toString(),
                    'faculty' => $meeting->faculty->name,
                    'day' => SectionMeeting::dayOptions()[$meeting->day_of_week] ?? 'Unscheduled',
                    'time' => substr((string) $meeting->starts_at, 0, 5).'-'.substr((string) $meeting->ends_at, 0, 5),
                    'room' => $meeting->room_id !== null ? $meeting->room->code : 'Not required',
                    'modality' => SectionMeeting::modalityOptions()[$modality] ?? str((string) $modality)->headline()->toString(),
                ];
            })
            ->values()
            ->all();
    }

    private function requiredSetting(string $key): string
    {
        $value = trim((string) getenv($key));
        $this->assertNotSame('', $value, "{$key} is required for TAL-96B2 cloud acceptance.");

        return $value;
    }

    private function boundedIntegerSetting(string $key, int $default, int $minimum, int $maximum): int
    {
        $rawValue = trim((string) getenv($key));
        $value = $rawValue === '' ? $default : filter_var($rawValue, FILTER_VALIDATE_INT);

        $this->assertIsInt($value, "{$key} must be an integer.");
        $this->assertGreaterThanOrEqual($minimum, $value, "{$key} is below the supported minimum.");
        $this->assertLessThanOrEqual($maximum, $value, "{$key} exceeds the supported maximum.");

        return $value;
    }
}
