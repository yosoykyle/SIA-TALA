<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\AcademicSetup\ActivateProgramAuthority;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverRequest;
use App\Actions\Scheduling\AdjustCandidateMeeting;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\RevisePublishedTimetable;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleReleaseNotificationService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Mail\ScheduleReleasedMail;
use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\ProgramAuthority;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AcademicAuthorityToPublishedTimetableJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleRegistrar,
            'guard_name' => 'web',
        ]);
        Role::query()->firstOrCreate([
            'name' => User::StaffRoleAcademicHead,
            'guard_name' => 'web',
        ]);
        Role::query()->firstOrCreate([
            'name' => User::StaffRoleFaculty,
            'guard_name' => 'web',
        ]);
        Permission::findOrCreate('manage-schedules', 'web');
        Role::findByName(User::StaffRoleRegistrar, 'web')->givePermissionTo('manage-schedules');
    }

    public function test_complete_authority_to_publication_journey_preserves_correction_recovery_and_projections(): void
    {
        Mail::fake();
        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
        $registrar = $this->registrar();
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);

        $authority = ProgramAuthority::factory()->create([
            'curriculum_source_reference' => 'SYNTH-DIT-CURRICULUM-2026',
            'state' => ProgramAuthority::StateDraft,
        ]);
        app(ActivateProgramAuthority::class)->execute($authority, $registrar);
        $program = $authority->program;
        $term = Term::factory()->create([
            'state' => Term::StateDraft,
            'default_max_units' => 21,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create();
        $this->completeCalendar($package);
        app(ActivateTermCalendarPackage::class)->execute($package, $registrar);
        $term->refresh();
        CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'process_key' => 'scheduling',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);

        $course = Course::factory()->create(['code' => 'S3-JOURNEY']);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'authority_reference' => 'SYNTH-COURSE-AUTHORITY-2026',
            'effective_from' => now()->subMonth()->toDateString(),
            'credit_units' => 1,
        ]);
        CourseComponent::factory()->for($specification)->create([
            'weekly_contact_hours' => 1,
            'meeting_pattern' => '1x60',
            'room_type_default' => Room::TypeLectureRoom,
            'required_room_feature_keys' => [],
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification, 'courseSpecification')
            ->create([
                'term_type' => $term->type,
                'term_label' => $term->label,
            ]);
        $cohort = TermCohort::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'confirmed_count' => 20,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StatePendingScheduling,
            'expected_count' => 20,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $specification->id,
            'class_reference' => 'SYNTH-S3-CLASS-001',
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
            'capacity' => 20,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 20,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $room = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 30,
            'is_active' => true,
        ]);
        $replacementRoom = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 30,
            'is_active' => true,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);

        app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 20]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            $term,
            $faculty,
            $faculty,
            'Available',
            [],
        );
        $demandSummary = app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);

        $this->assertSame(
            1,
            $demandSummary['ready'],
            json_encode(SchedulingDemand::query()->first()?->readiness_findings, JSON_THROW_ON_ERROR),
        );
        $this->assertTrue(app(ReadyTermPlanningProjection::class)->forTerm($term)['ready']);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusQueued,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['capture' => 'pending'],
            'input_hash' => hash('sha256', 'slice-3-journey-pending'),
            'solver_version' => 'pending-dispatch',
            'candidate_state' => 'Queued',
        ]);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $solverResult = app(LocalStubSchedulingSolverClient::class)
            ->solve(new SchedulingSolverRequest($snapshot, 'slice3-journey'))
            ->payload();
        $this->assertSame(ScheduleGenerationRun::ContractVersion, $snapshot['contract_version']);
        $this->assertSame('feasible', $solverResult['solver_status'], json_encode($solverResult['assignments'], JSON_THROW_ON_ERROR));
        $ingestSummary = app(ScheduleCloudResultIngestor::class)->ingest($run, $solverResult);
        $this->assertSame(
            ScheduleGenerationRun::StatusUnderReview,
            $run->fresh()->status,
            json_encode($ingestSummary, JSON_THROW_ON_ERROR),
        );
        $candidate = $run->fresh('candidateRows')->candidateRows->sole();

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->fresh()->status);

        try {
            app(AdjustCandidateMeeting::class)->execute(
                $candidate,
                $registrar,
                ['day_of_week' => 7],
                'Prove invalid local correction is atomic.',
            );
            $this->fail('An invalid local correction created a successor candidate.');
        } catch (ValidationException) {
            $this->assertSame(1, ScheduleGenerationRun::query()->where('term_id', $term->id)->count());
        }

        $candidateSuccessor = app(AdjustCandidateMeeting::class)->execute(
            $candidate,
            $registrar,
            [
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => 1,
                'starts_at' => '10:00:00',
                'ends_at' => '11:00:00',
            ],
            'Apply one valid attributable local correction.',
            'SYNTH-CORRECTION-001',
        );
        $accepted = app(ReviewTimetableCandidate::class)->accept(
            $candidateSuccessor,
            $registrar,
            'The complete corrected candidate passed Registrar review.',
        );
        $publishedRun = app(SchedulePublishService::class)->publish(
            $accepted,
            $registrar,
            'Publish the reviewed synthetic timetable.',
            authorityReference: 'SYNTH-TIMETABLE-SIGNOFF-001',
        );
        $publishedVersion = PublishedTimetableVersion::query()
            ->where('schedule_run_id', $publishedRun->id)
            ->sole();
        $publishedMeeting = $publishedVersion->meetings()->sole();

        $this->assertSame(PublishedTimetableVersion::StatePublished, $publishedVersion->state);
        $this->assertSame($faculty->id, $publishedMeeting->faculty_user_id);
        $this->assertSame(0, StudentScheduleBinding::query()->count());
        Mail::assertQueued(ScheduleReleasedMail::class, 1);

        $failedDelivery = OperationalEvent::query()
            ->where('related_record_type', PublishedTimetableVersion::class)
            ->where('related_record_id', $publishedVersion->id)
            ->sole();
        $failedDelivery->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
        ])->save();
        app(ScheduleReleaseNotificationService::class)->resend($failedDelivery, $registrar);
        Mail::assertQueued(ScheduleReleasedMail::class, 2);

        $successorVersion = app(RevisePublishedTimetable::class)->execute(
            $publishedVersion,
            $registrar,
            [$publishedMeeting->id => ['room_id' => $replacementRoom->id]],
            'SYNTH-TIMETABLE-SIGNOFF-002',
            'Move the class after an attributable room correction.',
        );

        $this->assertSame(PublishedTimetableVersion::StateSuperseded, $publishedVersion->fresh()->state);
        $this->assertSame($replacementRoom->id, $successorVersion->meetings->sole()->room_id);
        $this->assertSame($publishedMeeting->id, $successorVersion->meetings->sole()->supersedes_meeting_id);
        $this->assertSame(0, StudentScheduleBinding::query()->count());
    }

    public function test_program_authority_activation_is_failed_first_attributable_and_effective_dated(): void
    {
        $registrar = $this->registrar();
        $authority = ProgramAuthority::factory()->create([
            'curriculum_source_reference' => null,
            'state' => ProgramAuthority::StateDraft,
        ]);

        try {
            app(ActivateProgramAuthority::class)->execute($authority, $registrar);
            $this->fail('Authority without an attributable curriculum source was activated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        $this->assertSame(ProgramAuthority::StateDraft, $authority->fresh()->state);

        $authority->update(['curriculum_source_reference' => 'SYNTH-BSIT-CURRICULUM-2026']);
        $active = app(ActivateProgramAuthority::class)->execute($authority->fresh(), $registrar);

        $this->assertSame(ProgramAuthority::StateActive, $active->state);
        $this->assertSame($registrar->id, $active->recorded_by);

        $overlap = ProgramAuthority::factory()->for($active->program)->create([
            'effective_from' => $active->effective_from,
            'state' => ProgramAuthority::StateDraft,
        ]);

        $this->expectException(ValidationException::class);
        app(ActivateProgramAuthority::class)->execute($overlap, $registrar);
    }

    public function test_exact_terms_activate_independently_after_failed_first_calendar_readiness(): void
    {
        $registrar = $this->registrar();
        $first = TermCalendarPackage::factory()->create();
        $second = TermCalendarPackage::factory()->create([
            'term_id' => Term::factory()->state(['type' => Term::TypeSecondSemester]),
        ]);

        $readiness = app(TermCalendarPackageReadinessService::class)->for($first);
        $this->assertFalse($readiness['ready']);
        $this->assertContains('window_enrollment_invalid', collect($readiness['blockers'])->pluck('code')->all());

        foreach ([$first, $second] as $package) {
            $this->completeCalendar($package);
            app(ActivateTermCalendarPackage::class)->execute($package->fresh(), $registrar);
        }

        $this->assertSame(TermCalendarPackage::StateActive, $first->fresh()->state);
        $this->assertSame(TermCalendarPackage::StateActive, $second->fresh()->state);
        $this->assertSame(Term::StateActive, $first->term->fresh()->state);
        $this->assertSame(Term::StateActive, $second->term->fresh()->state);
    }

    public function test_special_term_never_activates_from_an_invented_default_schedule(): void
    {
        $registrar = $this->registrar();
        $special = TermCalendarPackage::factory()->create([
            'term_id' => Term::factory()->state(['type' => Term::TypeSummer]),
            'special_term_schedule_basis' => null,
        ]);
        $this->completeCalendar($special);

        try {
            app(ActivateTermCalendarPackage::class)->execute($special->fresh(), $registrar);
            $this->fail('Special Term without its approved schedule basis was activated.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'approved particular schedule',
                implode(' ', $exception->errors()['readiness']),
            );
        }

        $special->update([
            'special_term_schedule_basis' => 'SYNTH-ST-2026 approved class-hour and class-day schedule',
        ]);

        $active = app(ActivateTermCalendarPackage::class)->execute($special->fresh(), $registrar);
        $this->assertSame(TermCalendarPackage::StateActive, $active->state);
    }

    public function test_published_timetable_revision_creates_a_complete_immutable_successor(): void
    {
        $registrar = $this->registrar();
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['term_id' => $term->id],
            'input_hash' => hash('sha256', 'immutable-revision-input'),
            'contract_version' => 'legacy-revision-fixture',
            'solver_version' => ScheduleGenerationRun::SolverVersion,
            'model_version' => 'cp-sat-v2',
            'quality_policy' => ScheduleGenerationRun::QualityPolicyLexicographic,
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'SYNTH-SIGNOFF-001',
            'source_versions' => ['contract_version' => ScheduleGenerationRun::ContractVersion],
            'content_hash' => hash('sha256', 'published-version-one'),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        $meeting = PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $version->id,
            'section_id' => Section::factory()->create()->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => Room::factory()->create()->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'modality' => 'FACE_TO_FACE',
            'location_label' => 'Room 101',
        ]);

        $successor = app(RevisePublishedTimetable::class)->execute(
            $version,
            $registrar,
            [$meeting->id => ['starts_at' => '10:00:00', 'ends_at' => '11:30:00']],
            'SYNTH-SIGNOFF-002',
            'Approved room-conflict correction.',
        );

        $this->assertSame(PublishedTimetableVersion::StateSuperseded, $version->fresh()->state);
        $this->assertSame('08:00:00', (string) $meeting->fresh()->starts_at);
        $this->assertSame(2, $successor->version);
        $this->assertSame($version->id, $successor->supersedes_version_id);
        $this->assertSame('10:00:00', (string) $successor->meetings->sole()->starts_at);
        $this->assertSame($meeting->id, $successor->meetings->sole()->supersedes_meeting_id);
    }

    public function test_canonical_workbenches_are_connected_and_academic_head_is_read_only(): void
    {
        $registrar = $this->registrar();
        $academicHead = User::factory()->create(['status' => User::StatusActive]);
        $academicHead->assignRole(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();

        Livewire::actingAs($registrar)
            ->test(CatalogCurriculaWorkbench::class)
            ->assertSee('Academic authority at a glance')
            ->assertSee('Import previews');

        Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class)
            ->call('selectTerm', $term->id)
            ->call('showTab', 'correction')
            ->assertSee('Candidate Correction')
            ->assertSee('one-meeting correction');

        Livewire::actingAs($academicHead)
            ->test(CatalogCurriculaWorkbench::class)
            ->assertSee('Academic Head access is read-only');
    }

    public function test_candidate_acceptance_and_rejection_are_attributable_non_official_decisions(): void
    {
        $registrar = $this->registrar();
        $term = Term::factory()->create();
        $accepted = $this->candidateRun($term, 'accepted-candidate');

        $accepted = app(ReviewTimetableCandidate::class)->accept(
            $accepted,
            $registrar,
            'Registrar reviewed complete coverage and current validation evidence.',
        );

        $this->assertSame('Accepted', $accepted->candidate_state);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $accepted->status);
        $this->assertSame($registrar->id, $accepted->candidate_reviewed_by);
        $this->assertNull($accepted->published_at);

        $rejected = $this->candidateRun($term, 'rejected-candidate');
        $rejected = app(ReviewTimetableCandidate::class)->reject(
            $rejected,
            $registrar,
            'Candidate quality evidence requires a new generation request.',
        );

        $this->assertSame('Rejected', $rejected->candidate_state);
        $this->assertSame(ScheduleGenerationRun::StatusBlocked, $rejected->status);
        $this->assertNull($rejected->published_at);
    }

    public function test_coordinated_slice_three_fixture_preserves_six_cohorts_forty_seven_places_nine_faculty_and_ten_rooms(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $programs = collect([
            ['code' => 'DBM', 'name' => 'Diploma in Business Management'],
            ['code' => 'DIT', 'name' => 'Diploma in Information Technology'],
            ['code' => 'DTHM', 'name' => 'Diploma in Tourism and Hospitality Management'],
        ])->map(fn (array $program): Program => Program::factory()->create($program));
        $cohortCounts = [8, 8, 8, 8, 8, 7];

        foreach ($programs as $programIndex => $program) {
            $curriculum = CurriculumVersion::factory()->for($program)->create([
                'state' => CurriculumVersion::StateActive,
            ]);

            foreach (array_slice($cohortCounts, $programIndex * 2, 2) as $cohortIndex => $count) {
                TermCohort::factory()->create([
                    'term_id' => $term->id,
                    'program_id' => $program->id,
                    'curriculum_version_id' => $curriculum->id,
                    'reference' => 'SYNTH-'.$program->code.'-'.($cohortIndex + 1),
                    'forecast_count' => $count,
                    'confirmed_count' => $count,
                ]);
            }
        }

        $faculty = User::factory()->count(9)->create(['status' => User::StatusActive]);
        $faculty->each->assignRole(User::StaffRoleFaculty);
        Room::factory()->count(10)->create(['is_active' => true]);

        $this->assertSame(6, TermCohort::query()->whereBelongsTo($term)->count());
        $this->assertSame(47, (int) TermCohort::query()->whereBelongsTo($term)->sum('confirmed_count'));
        $this->assertSame(9, User::role(User::StaffRoleFaculty)->count());
        $this->assertSame(10, Room::query()->where('is_active', true)->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
    }

    public function test_late_faculty_availability_correction_is_attributable_and_stales_the_candidate(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'UnderReview',
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            $term,
            $faculty,
            $faculty,
            'Available',
            [['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']],
        );

        try {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                $term,
                $faculty,
                $faculty,
                'Available',
                [],
            );
            $this->fail('A later Faculty declaration was accepted without an attributable reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction_reason', $exception->errors());
        }

        $corrected = app(RecordFacultyAvailabilityDeclaration::class)->execute(
            $term,
            $faculty,
            $faculty,
            'Available',
            [],
            'Faculty confirmed the earlier conflict was removed.',
        );

        $this->assertSame(2, $corrected->version);
        $this->assertSame('Faculty confirmed the earlier conflict was removed.', $corrected->correction_reason);
        $this->assertSame('Stale', $run->fresh()->candidate_state);
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }

    private function completeCalendar(TermCalendarPackage $package): void
    {
        foreach (['Enrollment', 'ExaminationPeriod', 'GradeEntry'] as $index => $windowType) {
            TermCalendarWindow::factory()->for($package, 'package')->create([
                'window_type' => $windowType,
                'opens_on' => now()->addDays($index)->toDateString(),
                'closes_on' => now()->addDays($index + 10)->toDateString(),
            ]);
        }

        TermTeachingGridRow::factory()->for($package, 'package')->create();
    }

    private function candidateRun(Term $term, string $key): ScheduleGenerationRun
    {
        $demand = SchedulingDemand::factory()->create();
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'input_snapshot' => ['contract_version' => ScheduleGenerationRun::ContractVersion],
            'input_hash' => hash('sha256', $key),
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'solver_version' => ScheduleGenerationRun::SolverVersion,
            'quality_policy' => ScheduleGenerationRun::QualityPolicyLexicographic,
            'candidate_key' => $key,
            'candidate_version' => 1,
            'candidate_state' => 'UnderReview',
        ]);
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => User::factory()->create()->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        return $run;
    }
}
