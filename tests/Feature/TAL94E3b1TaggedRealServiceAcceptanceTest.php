<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentPlacementService;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\IntegrationStatus;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Student\Pages\ScheduleView;
use App\Mail\ScheduleReleasedMail;
use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94E3b1TaggedRealServiceAcceptanceTest extends TestCase
{
    private const CanonicalContract = 'tal94-demand-v2';

    private const SolverVersion = 'cloud-cp-sat-tal94-demand-v2';

    public function test_tagged_real_service_completes_the_queued_schedule_workflow_and_rejects_a_bad_audience(): void
    {
        if ((string) getenv('TALA_E3B1_ACCEPTANCE') !== '1') {
            $this->markTestSkipped('Set TALA_E3B1_ACCEPTANCE=1 to call the private tagged Cloud Run revision.');
        }

        $tagUrl = $this->requiredSetting('TALA_E3B1_TAG_URL');
        $canonicalUrl = $this->requiredSetting('TALA_E3B1_CANONICAL_URL');
        $credentialsPath = $this->requiredSetting('TALA_E3B1_CREDENTIALS');

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);
        $this->assertFileExists($credentialsPath);
        $this->assertSame(0, ScheduleGenerationRun::query()->count(), 'Reset test_tala_db before external acceptance.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Clear the database queue before external acceptance.');

        $this->seed();
        $this->configureCloudRun($tagUrl, $canonicalUrl, $credentialsPath);
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar, 'e3b1.registrar@example.test');
        $faculty = $this->staff(User::StaffRoleFaculty, 'e3b1.faculty@example.test');
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin, 'e3b1.admin@example.test');
        $source = $this->schedulingSource('accepted', $faculty);

        $demandSummary = app(GenerateSchedulingDemand::class)->forTerm($registrar, $source['term']);
        $this->assertSame(2, $demandSummary['total']);
        $this->assertSame(2, $demandSummary['ready']);
        $this->assertSame(0, $demandSummary['action_required']);

        $run = app(ScheduleGenerationService::class)->generate($source['term'], $registrar);

        $this->assertSame(ScheduleGenerationRun::StatusQueued, $run->status);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'scheduling')->count());

        $worker = $this->runOneWorker($tagUrl, $canonicalUrl, $credentialsPath);
        $this->assertTrue($worker->successful(), $this->safeProcessFailure($worker, $credentialsPath));

        $run->refresh();
        $candidates = $run->candidateRows()->orderBy('scheduling_demand_id')->orderBy('meeting_sequence')->get();
        $expectedCoverage = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $source['term']->id))
            ->orderBy('id')
            ->get()
            ->flatMap(fn (SchedulingDemand $demand): array => collect(range(1, $demand->meeting_count))
                ->map(fn (int $sequence): string => $demand->id.':'.$sequence)
                ->all())
            ->values()
            ->all();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame(self::SolverVersion, $run->solver_version);
        $this->assertSame(self::CanonicalContract, $run->model_version);
        $this->assertSame(self::CanonicalContract, data_get($run->input_snapshot, 'contract_version'));
        $this->assertSame(2, $candidates->count());
        $this->assertSame(
            $expectedCoverage,
            $candidates->map(fn (CandidateScheduleRow $row): string => $row->scheduling_demand_id.':'.$row->meeting_sequence)->all(),
        );
        $this->assertTrue($candidates->every(fn (CandidateScheduleRow $row): bool => $row->status === CandidateScheduleRow::StatusOk));
        $this->assertSame(2, data_get($run->diagnostics, 'solver_result.summary.assigned_count'));
        $this->assertSame(0, data_get($run->diagnostics, 'solver_result.summary.unassigned_count'));
        $this->assertSame(0, data_get($run->diagnostics, 'solver_result.summary.hard_violation_count'));
        $this->assertSame(0, DB::table('jobs')->where('queue', 'scheduling')->count());

        $attempt = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeSolverDispatchAttempt)
            ->where('related_record_type', ScheduleGenerationRun::class)
            ->where('related_record_id', $run->id)
            ->sole();
        $this->assertSame(OperationalEvent::StatusProcessed, $attempt->status);
        $this->assertSame(1, data_get($attempt->diagnostics, 'attempt'));

        $published = app(SchedulePublishService::class)->publish($run, $registrar);
        $meetings = $published->sectionMeetings()->orderBy('id')->get();

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
        $this->assertSame($registrar->id, $published->published_by);
        $this->assertSame(1, $published->publication_version);
        $this->assertSame('accepted', data_get($published->diagnostics, 'current_revalidation.status'));
        $this->assertSame(0, data_get($published->diagnostics, 'current_revalidation.summary.hard_violation_count'));
        $this->assertCount(2, $meetings);
        $this->assertTrue($meetings->every(fn (SectionMeeting $meeting): bool => $meeting->state === SectionMeeting::StateActive));

        Mail::assertQueuedCount(1);
        Mail::assertQueued(ScheduleReleasedMail::class, fn (ScheduleReleasedMail $mail): bool => $mail->hasTo($faculty->email));
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'schedule_released_email')->where('status', 'PENDING')->count());

        $enrollment = Enrollment::factory()->for($source['term'])->create(['status' => 'capacity_pending']);
        $student = $enrollment->studentProfile->user;
        $student->forceFill([
            'name' => 'E3b1 Student',
            'email' => 'e3b1.student@example.test',
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ])->save();
        $student->assignRole('student');

        $placement = app(EnrollmentPlacementService::class)->confirm($enrollment, $source['section']->id, $registrar);
        $bindings = StudentScheduleBinding::query()
            ->where('course_enrollment_id', $placement['course_enrollment']->id)
            ->where('is_active', true)
            ->get();

        $this->assertSame(2, $placement['bindings']);
        $this->assertCount(2, $bindings);

        Livewire::actingAs($superAdmin)
            ->test(IntegrationStatus::class)
            ->assertOk()
            ->assertSee('Integration Status');
        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $published->id])
            ->assertOk()
            ->assertSee(self::SolverVersion);
        Livewire::actingAs($registrar)
            ->test(ListSectionMeetings::class)
            ->assertOk()
            ->assertCanSeeTableRecords($meetings);
        Livewire::actingAs($faculty)
            ->test(FacultySchedule::class)
            ->assertOk()
            ->assertCanSeeTableRecords($meetings);
        Livewire::actingAs($student)
            ->test(ScheduleView::class)
            ->assertOk()
            ->assertCanSeeTableRecords($bindings);

        $rejected = $this->schedulingSource('rejected', $faculty);
        $rejectedSummary = app(GenerateSchedulingDemand::class)->forTerm($registrar, $rejected['term']);
        $this->assertSame(2, $rejectedSummary['ready']);
        $failedRun = app(ScheduleGenerationService::class)->generate($rejected['term'], $registrar);

        $this->runOneWorker(
            $tagUrl,
            'https://rejected-audience.invalid',
            $credentialsPath,
            expectFailure: true,
        );

        $failedRun->refresh();
        $failureEvent = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeSolverDispatchAttempt)
            ->where('related_record_type', ScheduleGenerationRun::class)
            ->where('related_record_id', $failedRun->id)
            ->sole();
        $encodedFailure = json_encode([
            'run' => $failedRun->diagnostics,
            'event' => $failureEvent->diagnostics,
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(ScheduleGenerationRun::StatusFailed, $failedRun->status);
        $this->assertSame(OperationalEvent::StatusFailed, $failureEvent->status);
        $this->assertSame(SchedulingSolverTransportException::ClassificationClientError, data_get($failureEvent->diagnostics, 'classification'));
        $this->assertSame(1, data_get($failureEvent->diagnostics, 'attempt'));
        $this->assertFalse((bool) data_get($failureEvent->diagnostics, 'retryable'));
        $this->assertTrue((bool) data_get($failureEvent->diagnostics, 'final'));
        $this->assertSame(0, CandidateScheduleRow::query()->where('schedule_run_id', $failedRun->id)->count());
        $this->assertSame(0, SectionMeeting::query()->where('schedule_run_id', $failedRun->id)->count());
        $this->assertStringNotContainsString($credentialsPath, $encodedFailure);
        $this->assertStringNotContainsString(basename($credentialsPath), $encodedFailure);
    }

    private function configureCloudRun(string $tagUrl, string $audience, string $credentialsPath): void
    {
        config()->set([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'tala_integrations.scheduling_solver.driver' => 'cloud_run',
            'tala_integrations.scheduling_solver.url' => $tagUrl,
            'tala_integrations.scheduling_solver.audience' => $audience,
            'tala_integrations.scheduling_solver.credentials_path' => $credentialsPath,
            'tala_integrations.scheduling_solver.timeout_seconds' => 300,
            'tala_integrations.scheduling_solver.connect_timeout_seconds' => 10,
        ]);
        $this->app->forgetInstance(SchedulingSolverClient::class);
    }

    private function runOneWorker(
        string $tagUrl,
        string $audience,
        string $credentialsPath,
        bool $expectFailure = false,
    ): ProcessResult {
        $result = Process::path(base_path())
            ->timeout(420)
            ->env([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'test_tala_db',
                'QUEUE_CONNECTION' => 'database',
                'MAIL_MAILER' => 'array',
                'TALA_SCHEDULING_SOLVER_DRIVER' => 'cloud_run',
                'TALA_SCHEDULING_SOLVER_URL' => $tagUrl,
                'TALA_SCHEDULING_SOLVER_AUDIENCE' => $audience,
                'TALA_SCHEDULING_SOLVER_CREDENTIALS' => $credentialsPath,
                'TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS' => '300',
                'TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS' => '10',
            ])
            ->run([
                PHP_BINARY,
                'artisan',
                'queue:work',
                'database',
                '--queue=scheduling',
                '--once',
                '--tries=1',
                '--timeout=360',
                '--no-interaction',
            ]);

        if ($expectFailure) {
            $this->assertContains($result->exitCode(), [0, 1]);
        }

        return $result;
    }

    private function safeProcessFailure(ProcessResult $result, string $credentialsPath): string
    {
        return str_replace(
            [$credentialsPath, basename($credentialsPath)],
            '[redacted]',
            trim($result->output().PHP_EOL.$result->errorOutput()),
        );
    }

    /**
     * @return array{term:Term,section:Section}
     */
    private function schedulingSource(string $key, User $faculty): array
    {
        $term = Term::factory()->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'E3b1 '.ucfirst($key).' Term',
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'process_key' => 'scheduling',
            'start_at' => now()->addWeek(),
            'end_at' => now()->addWeeks(2),
            'state' => CalendarEvent::StateActive,
        ]);
        $program = Program::factory()->create(['code' => 'E3B'.mb_strtoupper(mb_substr($key, 0, 1))]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'E3B1'.mb_strtoupper(mb_substr($key, 0, 1))]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'E3b1 '.ucfirst($key).' Scheduling',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
            'same_faculty_default' => true,
        ]);
        CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 2.00,
            'room_type_default' => Room::TypeLaboratory,
            'sequence' => 2,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 30,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'E3B1-'.mb_strtoupper($key),
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'E3b1 '.ucfirst($key).' Cohort',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);
        FacultyTermLoadOverride::factory()->for($faculty, 'faculty')->for($term)->create([
            'default_max_units_snapshot' => 21.00,
            'approved_overload_units' => 3.00,
            'is_active' => true,
        ]);
        Room::factory()->create([
            'name' => 'E3b1 '.ucfirst($key).' Lecture',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        Room::factory()->create([
            'name' => 'E3b1 '.ucfirst($key).' Laboratory',
            'room_type' => Room::TypeLaboratory,
            'capacity' => 40,
            'is_active' => true,
        ]);

        return compact('term', 'section');
    }

    private function staff(string $role, string $email): User
    {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create([
            'name' => 'E3b1 '.User::staffRoleOptions()[$role],
            'email' => $email,
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function requiredSetting(string $key): string
    {
        $value = trim((string) getenv($key));
        $this->assertNotSame('', $value, "{$key} is required for TAL-94E3b1 external acceptance.");

        return $value;
    }
}
