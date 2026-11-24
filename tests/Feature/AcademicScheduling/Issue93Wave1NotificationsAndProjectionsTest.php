<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverRequest;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\AdmissionCycles\Pages\ViewAdmissionCycle;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Filament\Student\Pages\Dashboard as StudentDashboard;
use App\Filament\Student\Pages\ScheduleView;
use App\Mail\FacultyAvailabilityRequestedMail;
use App\Models\AdmissionCycle;
use App\Models\CalendarEvent;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93Wave1NotificationsAndProjectionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty, User::StaffRoleAcademicHead] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        Role::findByName(User::StaffRoleRegistrar, 'web')->givePermissionTo([
            Permission::query()->firstOrCreate(['name' => 'manage-schedules', 'guard_name' => 'web']),
            Permission::query()->firstOrCreate(['name' => 'manage-admission-setup', 'guard_name' => 'web']),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    #[Test]
    public function availability_request_retries_failed_deliveries_instead_of_skipping_and_resend_refreshes_email_snapshot(): void
    {
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty, ['email' => 'initial.faculty@example.test']);
        $package = $this->activePackage($registrar);
        app(RecordFacultyAvailabilityDeadline::class)->execute(
            $package->term,
            $registrar,
            '2026-08-15 09:00:00',
            'Approved availability deadline',
            null,
            0,
        );

        $service = app(FacultyAvailabilityRequestService::class);
        $initial = $service->request($package->fresh(), [$faculty->id], $registrar)->sole();
        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, 1);

        // Simulate failed delivery and corrected faculty email; re-running request() must retry, not skip.
        $initial->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
            'processed_at' => now(),
            'diagnostics' => ['reason' => 'SMTP timeout'],
        ])->save();
        $faculty->forceFill(['email' => 'updated.faculty@example.test'])->save();

        $retried = $service->request($package->fresh(), [$faculty->id], $registrar)->sole()->fresh();
        $this->assertSame($initial->id, $retried->id);
        $this->assertSame(OperationalEvent::StatusPending, $retried->status);
        $this->assertNull($retried->failed_at);
        $this->assertSame('updated.faculty@example.test', data_get($retried->recipient_snapshot, 'email'));
        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, 2);

        // Explicit resend() also refreshes recipient email snapshot when a delivery fails again.
        $retried->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
            'processed_at' => now(),
        ])->save();
        $faculty->forceFill(['email' => 'final.faculty@example.test'])->save();

        $resent = $service->resend($retried->fresh(), $registrar);
        $this->assertSame(OperationalEvent::StatusPending, $resent->status);
        $this->assertSame('final.faculty@example.test', data_get($resent->recipient_snapshot, 'email'));
        Mail::assertQueued(FacultyAvailabilityRequestedMail::class, 3);
    }

    #[Test]
    public function teaching_status_change_and_calendar_grid_shrink_write_faculty_database_notifications(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = $this->activePackage($registrar, [
            ['day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '20:00:00', 'breaks' => []],
        ]);
        $term = $package->term;

        FacultyAvailabilityRequestService::notifyTeachingStatusChanged($faculty, $term, true, 'Approved study leave');
        FacultyAvailabilityRequestService::notifyTeachingStatusChanged($faculty, $term, false);

        $facultyNotifications = $faculty->notifications()->get();
        $this->assertCount(2, $facultyNotifications);
        $this->assertTrue($facultyNotifications->contains(
            fn ($n): bool => str_contains((string) data_get($n->data, 'title'), "Teaching status updated for {$term->label}")
                && str_contains((string) data_get($n->data, 'body'), 'Approved study leave'),
        ));
        $this->assertTrue($facultyNotifications->contains(
            fn ($n): bool => str_contains((string) data_get($n->data, 'title'), "Teaching status restored for {$term->label}"),
        ));

        // Record Faculty availability until 7:00 PM, then activate a successor package ending at 5:00 PM.
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [
                ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '19:00'],
            ],
        );

        $successorDraft = TermCalendarPackage::factory()->for($term)->create([
            'version' => 2,
            'state' => TermCalendarPackage::StateDraft,
        ]);
        $successorDraft->teachingGridRows()->create([
            'day_of_week' => 1,
            'starts_at' => '07:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);
        app(ActivateTermCalendarPackage::class)->execute($successorDraft->fresh(), $registrar);

        $this->assertTrue(
            $faculty->notifications()->get()->contains(
                fn ($n): bool => data_get($n->data, 'title') === "Action needed: update your teaching times for {$term->label}"
                    && str_contains((string) data_get($n->data, 'body'), '1 of your saved teaching windows falls outside the updated hours'),
            ),
        );
    }

    #[Test]
    public function solver_ingestion_and_late_availability_conflict_write_registrar_database_notifications(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $program = Program::factory()->create();
        $package = $this->activePackage($registrar, [
            ['day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '19:00:00', 'breaks' => []],
        ]);
        $term = $package->term;

        CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'process_key' => 'scheduling',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);

        $course = Course::factory()->create(['code' => 'NTF101']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'credit_units' => 1,
        ]);
        $component = CourseComponent::factory()->for($spec)->create([
            'weekly_contact_hours' => 1,
            'meeting_pattern' => '1x60',
            'room_type_default' => Room::TypeLectureRoom,
            'required_room_feature_keys' => [],
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
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
            'course_specification_id' => $spec->id,
            'class_reference' => 'NTF-CLASS-001',
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
            'capacity' => 20,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 20,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $room = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 30,
            'is_active' => true,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);

        app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 20]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);

        // 1. Feasible ingestion sends success bell notification to requester.
        $feasibleRun = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusQueued,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['capture' => 'pending'],
            'input_hash' => hash('sha256', 'feasible-'.Str::uuid()),
            'solver_version' => 'pending-dispatch',
            'candidate_state' => 'Queued',
        ]);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($feasibleRun);
        $solverResult = app(LocalStubSchedulingSolverClient::class)
            ->solve(new SchedulingSolverRequest($snapshot, 'notif-feasible'))
            ->payload();
        app(ScheduleCloudResultIngestor::class)->ingest($feasibleRun, $solverResult);

        $this->assertTrue(
            $registrar->notifications()->get()->contains(
                fn ($n): bool => data_get($n->data, 'title') === "Draft timetable ready for {$term->label}",
            ),
        );

        // 2. Blocked/infeasible ingestion sends warning bell notification to requester.
        $blockedRun = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusQueued,
            'requested_by' => $registrar->id,
            'input_snapshot' => $snapshot,
            'input_hash' => hash('sha256', 'blocked-'.Str::uuid()),
            'solver_version' => 'pending-dispatch',
            'candidate_state' => 'Queued',
        ]);
        app(ScheduleCloudResultIngestor::class)->ingest($blockedRun, [
            'solver_status' => 'infeasible',
            'assignments' => [],
            'infeasible_reasons' => ['No available room for demand'],
        ]);

        $this->assertTrue(
            $registrar->notifications()->get()->contains(
                fn ($n): bool => data_get($n->data, 'title') === "Timetable generation needs attention ({$term->label})",
            ),
        );

        // 3. Post-publication late faculty availability update conflicting with an active official meeting notifies Registrars.
        $publishedRun = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', 'pub-'.Str::uuid()),
            'solver_version' => 'test-solver',
            'published_by' => $registrar->id,
            'published_at' => now()->subHour(),
            'publication_version' => 1,
        ]);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->firstOrFail();
        SectionMeeting::query()->create([
            'schedule_run_id' => $publishedRun->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now()->subHour(),
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            correctionReason: 'Medical appointment on Mondays',
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );

        $this->assertTrue(
            $registrar->notifications()->get()->contains(
                fn ($n): bool => data_get($n->data, 'title') === "{$faculty->name} changed availability after publication"
                    && str_contains((string) data_get($n->data, 'body'), "1 official meeting in {$term->label} conflicts"),
            ),
        );
    }

    #[Test]
    public function student_schedule_view_and_dashboard_render_revision_banner_exam_period_without_authority_metadata_and_top_level_nav(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $student = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $student->assignRole('student');

        $program = Program::factory()->create(['code' => 'H5-BSIT']);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        $package = $this->activePackage($registrar);
        $term = $package->term;
        $package->forceFill([
            'authority_reference' => 'INTERNAL-BOARD-MEMO-99',
            'classes_start_on' => '2026-08-17',
            'classes_end_on' => '2026-12-18',
        ])->save();
        TermCalendarWindow::query()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeExaminationPeriod,
            'opens_on' => '2026-12-14',
            'closes_on' => '2026-12-18',
        ]);
        $package->datedExceptions()->create([
            'starts_on' => now()->addDays(5)->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
            'exception_type' => 'Holiday',
            'label' => 'Founders Day',
            'authority_reference' => 'INTERNAL-HOLIDAY-MEMO',
            'blocks_teaching' => true,
        ]);

        $course = Course::factory()->create(['code' => 'H5-IT201']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Web Engineering',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 3.00,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($spec)->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-2H5',
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $demand = SchedulingDemand::factory()->for($offering)->for($component)->for($group)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $room = Room::factory()->create(['code' => 'H5-RM302']);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', 'h5-'.Str::uuid()),
            'solver_version' => 'h5-test',
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'H5-AUTH',
            'publication_reason' => 'H5 test fixture',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'h5-ver-'.Str::uuid()),
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now()->subDay(),
        ]);
        PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $version->id,
            'section_id' => $section->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $room->code,
        ]);

        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $student->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now()->subDay(),
        ]);
        $cor = CorVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'published_timetable_version_id' => $version->id,
            'issued_by' => $registrar->id,
            'snapshot' => ['courses' => []],
        ]);
        $enrollment->update(['current_cor_version_id' => $cor->id]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'published_timetable_version_id' => $version->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
            'added_at' => now()->subDay(),
        ]);

        ScheduleRevisionEvent::query()->create([
            'term_id' => $term->id,
            'section_meeting_id' => $meeting->id,
            'change_type' => ScheduleRevisionEvent::ChangeTime,
            'reason' => 'Moved to morning lab block.',
            'effective_date' => '2026-09-01',
            'changed_by' => $registrar->id,
            'old_snapshot_json' => [
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '10:00:00',
            ],
            'new_snapshot_json' => [
                'day_of_week' => 2,
                'starts_at' => '10:00:00',
                'ends_at' => '12:00:00',
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->actingAs($student);

        // Top-level Student Hub navigation includes Class schedule and enables DB notifications.
        $this->assertTrue(Filament::getPanel('student')->hasDatabaseNotifications());
        $labels = collect(Filament::getPanel('student')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map->getLabel()
            ->values()
            ->all();
        $this->assertSame(['Overview', 'Class schedule', 'Enrollment', 'Academics', 'Fees and payments'], $labels);

        // ScheduleView renders breadcrumbs, H5 revision banner, Exam period, upcoming holiday, and zero internal authority metadata.
        $schedulePage = Livewire::actingAs($student)
            ->test(ScheduleView::class)
            ->assertOk()
            ->assertSee('Affected by recent change')
            ->assertSee('Time changed')
            ->assertSee('H5-IT201')
            ->assertSee('BSIT-2H5')
            ->assertSee('Was Monday · 8:00 AM–10:00 AM → Now Tuesday · 10:00 AM–12:00 PM')
            ->assertSee('Effective Sep 1, 2026')
            ->assertSee('Moved to morning lab block.')
            ->assertSee('Exam period:')
            ->assertSee('Dec 14–Dec 18, 2026')
            ->assertSee('Founders Day')
            ->assertDontSee('INTERNAL-BOARD-MEMO-99')
            ->assertDontSee('Authority not recorded');

        $this->assertSame(
            [StudentDashboard::getUrl(panel: 'student') => 'Overview', 'Class schedule'],
            $schedulePage->instance()->getBreadcrumbs(),
        );

        // Student Dashboard also surfaces the H5 revision banner and Open full schedule link.
        $this->actingAs($student)
            ->get('/student')
            ->assertOk()
            ->assertSee('Open full schedule')
            ->assertSee('Affected by recent change')
            ->assertSee('Moved to morning lab block.');
    }

    #[Test]
    public function registrar_cross_links_on_view_program_and_admission_cycle_point_directly_to_term_overview_and_term_timetable(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $package = $this->activePackage($registrar);
        $term = $package->term;
        $program = Program::factory()->create(['code' => 'XLNK-BSIT', 'is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'XLNK-101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => $term->type,
            'term_label' => $term->label,
        ]);
        $cohort = TermCohort::factory()->for($term)->for($curriculum)->create([
            'program_id' => $program->id,
            'reference' => 'XLNK-1A',
            'year_level' => 1,
            'confirmed_count' => 25,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'XLNK-1A-SEC',
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $section->cohorts()->attach($cohort->id, ['expected_count' => 25]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', 'xlnk-'.Str::uuid()),
            'solver_version' => 'xlnk-test',
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'XLNK-AUTH',
            'publication_reason' => 'Cross-link fixture',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'xlnk-v-'.Str::uuid()),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $version->id,
            'section_id' => $section->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'room_id' => Room::factory()->create()->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => 'Room 101',
        ]);

        $expectedTermCalendarUrl = TermCalendar::getUrl(['termId' => $term->id]);
        $expectedTermClassesUrl = TermClasses::getUrl(['termId' => $term->id, 'programId' => $program->id]);
        $expectedTimetableUrl = TermTimetable::getUrl([
            'termId' => $term->id,
            'timetableState' => 'official',
            'timetableView' => 'section',
            'viewFilter' => (string) $cohort->id,
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewProgram::class, ['record' => $program->id])
            ->call('switchTab', 'sections')
            ->set('selectedTermId', $term->id)
            ->assertSee('class places filled')
            ->assertSee('View timetable')
            ->assertSeeHtml('href="'.e($expectedTermClassesUrl).'"')
            ->assertSeeHtml('href="'.e($expectedTimetableUrl).'"');

        $cycle = AdmissionCycle::factory()->published()->for($term)->create();

        Livewire::actingAs($registrar)
            ->test(ViewAdmissionCycle::class, ['record' => $cycle->id])
            ->assertOk()
            ->assertSeeHtml('href="'.e($expectedTermCalendarUrl).'"');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
            ...$attributes,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<array{day_of_week:int,starts_at:string,ends_at:string,breaks:list<array{starts_at:string,ends_at:string}>}>|null  $rows
     */
    private function activePackage(User $registrar, ?array $rows = null): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->create();
        $rows ??= [
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => []],
        ];

        foreach ($rows as $row) {
            TermTeachingGridRow::query()->create([
                'term_calendar_package_id' => $package->id,
                ...$row,
            ]);
        }

        return app(ActivateTermCalendarPackage::class)->execute($package->fresh(), $registrar);
    }
}
