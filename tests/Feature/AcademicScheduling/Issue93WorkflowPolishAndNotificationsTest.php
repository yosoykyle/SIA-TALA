<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Actions\Admissions\AdmissionReviewGuard;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Actions\Finance\PaymentPostedNotificationService;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SaveGradeRosterDraft;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Actions\Scheduling\ScheduleRevisionNotificationService;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicantIntake;
use App\Models\ApplicationCorrectionItem;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\AcademicScheduling\Concerns\InteractsWithAcademicSchedulingFixtures;
use Tests\TestCase;

final class Issue93WorkflowPolishAndNotificationsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAcademicSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
            User::StaffRoleAccounting,
            'student',
            'applicant',
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function copied_draft_tracks_pure_subject_moves_in_diff_while_keeping_backward_compatible_counts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $lifecycle = app(CurriculumVersionLifecycleService::class);
        $workbench = app(CurriculumWorkbenchService::class);

        $program = Program::factory()->create(['code' => 'BSIT-MV1', 'duration_years' => 4, 'is_active' => true]);
        $ay2026 = AcademicYear::factory()->create(['label' => '2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31']);
        $ay2027 = AcademicYear::factory()->create(['label' => '2027-2028', 'starts_on' => '2027-08-01', 'ends_on' => '2028-05-31']);

        $sourceDraft = $lifecycle->createDraft($registrar, $program, 'BSIT-MV-2026', startingBatch: $ay2026);

        $keptSource = $workbench->quickPlaceSubject($registrar, $sourceDraft, [
            'code' => 'MV101',
            'title' => 'Foundations of Computing',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);
        $movedSource = $workbench->quickPlaceSubject($registrar, $sourceDraft, [
            'code' => 'MV102',
            'title' => 'Discrete Mathematics',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);

        $sourceActive = $lifecycle->activate($registrar, $sourceDraft);
        $copiedDraft = $lifecycle->copyToDraft($registrar, $sourceActive, 'BSIT-MV-2027', startingBatch: $ay2027);

        $copiedMovedEntry = $copiedDraft->entries()
            ->where('course_specification_id', $movedSource->course_specification_id)
            ->sole();

        $workbench->updatePlacement($registrar, $copiedMovedEntry, [
            'year_level' => 2,
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
            'requirement_group' => $copiedMovedEntry->requirement_group,
        ]);

        $diff = $workbench->diffEntriesAgainstSource($copiedDraft->fresh());

        $this->assertTrue($diff['has_source']);
        $this->assertSame(1, $diff['moved_count']);
        $this->assertSame('Moved', $diff['entry_statuses'][$copiedMovedEntry->id]);
        $this->assertSame('1', $diff['entry_moves'][$copiedMovedEntry->id]['from_year']);
        $this->assertSame(Term::TypeFirstSemester, $diff['entry_moves'][$copiedMovedEntry->id]['from_term_type']);
        $this->assertSame([
            'copied' => 1,
            'edited' => 0,
            'added' => 0,
            'removed' => 0,
        ], $diff['counts']);
    }

    #[Test]
    public function curriculum_move_guard_prerequisite_warning_moved_badge_and_undo_work_in_review_workbench(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $workbench = app(CurriculumWorkbenchService::class);
        $program = Program::factory()->create(['code' => 'BSCS-MV2', 'duration_years' => 4, 'is_active' => true]);
        $draft = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);

        $entryReq = $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'REQ101',
            'title' => 'Computer Programming 1',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);
        $entryDep = $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'DEP201',
            'title' => 'Computer Programming 2',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeSecondSemester,
            'room_schedule_preset' => 'lecture',
            'prerequisite_course_id' => $entryReq->courseSpecification->course_id,
        ]);

        $this->assertSame(
            1,
            CourseRequirement::query()
                ->where('course_specification_id', $entryDep->course_specification_id)
                ->where('related_course_id', $entryReq->courseSpecification->course_id)
                ->count(),
        );

        // Bulk move requires confirmation and surfaces prerequisite ordering warning before confirming.
        $page = Livewire::test(ReviewCurriculumVersion::class, ['record' => $draft->getRouteKey()])
            ->set('selectedEntryIds', [$entryDep->id])
            ->mountAction('bulkMoveSelectedEntries')
            ->setActionData([
                'year_level' => 1,
                'term_type' => Term::TypeFirstSemester,
                'confirm_move' => false,
            ]);

        $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
        $previewComponent = $schema?->getFlatComponents()['move_impact_preview'] ?? null;
        $this->assertNotNull($previewComponent);
        $this->assertStringContainsString(
            'not after its prerequisite REQ101',
            (string) $previewComponent->getContent()->toHtml(),
        );

        $page->callMountedAction()
            ->assertHasActionErrors(['confirm_move']);

        $this->assertSame(Term::TypeSecondSemester, $entryDep->fresh()->term_type);

        // Confirming the move executes placement, highlights destination row with Moved badge, and supports Undo move.
        $page->setActionData([
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'confirm_move' => true,
        ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('Moved from 1st Year')
            ->assertSee('Undo move')
            ->assertSeeHtml('is-moved');

        $this->assertSame(Term::TypeFirstSemester, $entryDep->fresh()->term_type);

        $page->call('undoRecentMoves')
            ->assertNotified('Move undone')
            ->assertDontSeeHtml('is-moved');

        $this->assertSame(Term::TypeSecondSemester, $entryDep->fresh()->term_type);

        // Single-subject inspector also guards year/semester moves with confirm_placement_move.
        $page->call('selectCurriculumSubject', $entryReq->id)
            ->set('subjectData.year_level', 2)
            ->set('subjectData.term_type', Term::TypeFirstSemester)
            ->call('saveCurriculumSubject')
            ->assertHasErrors(['subjectData.confirm_placement_move']);

        $this->assertSame('1', (string) $entryReq->fresh()->year_level);

        $page->set('subjectData.confirm_placement_move', true)
            ->call('saveCurriculumSubject')
            ->assertHasNoErrors();

        $this->assertSame('2', (string) $entryReq->fresh()->year_level);
        $this->assertArrayHasKey($entryReq->id, $page->get('recentlyMovedEntries'));
    }

    #[Test]
    public function cross_role_and_async_events_write_filament_database_notifications(): void
    {
        Mail::fake();
        Storage::fake('local');

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $studentUser = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $studentUser->assignRole('student');

        $term = Term::factory()->create([
            'label' => '1st Semester 2026-2027',
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(3)->toDateString(),
            'state' => Term::StateActive,
        ]);

        // 1) Schedule revision / cancellation -> affected Faculty & Student DB notifications.
        $program = Program::factory()->create(['is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'NOTIF-101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        CourseComponent::factory()->for($spec)->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create(['code' => 'NTF-A', 'state' => Section::StateOpen]);
        $group = SectionDeliveryGroup::factory()->for($section, 'section')->create();
        $demand = SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create(['term_offering_id' => $offering->id]);
        $oldRoom = Room::factory()->create(['code' => 'RM-101', 'name' => 'Room 101']);
        $newRoom = Room::factory()->create(['code' => 'RM-202', 'name' => 'Room 202']);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
            'published_at' => now(),
            'published_by' => $registrar->id,
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $newRoom->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:30:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        $studentProfile = StudentProfile::factory()->for($program)->for($curriculum)->for($studentUser, 'user')->create();
        $enrollment = Enrollment::factory()->for($term)->create([
            'student_profile_id' => $studentProfile->id,
            'credential_user_id' => $studentUser->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        $revisionEvent = ScheduleRevisionEvent::query()->create([
            'term_id' => $term->id,
            'section_meeting_id' => $meeting->id,
            'change_type' => ScheduleRevisionEvent::ChangeRoom,
            'reason' => 'Facility update.',
            'effective_date' => now()->toDateString(),
            'changed_by' => $registrar->id,
            'old_snapshot_json' => [
                'faculty_user_id' => $faculty->id,
                'room_id' => $oldRoom->id,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:30:00',
            ],
            'new_snapshot_json' => [
                'faculty_user_id' => $faculty->id,
                'room_id' => $newRoom->id,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:30:00',
            ],
            'affected_student_count' => 1,
            'affected_faculty_count' => 1,
        ]);

        $revisionService = app(ScheduleRevisionNotificationService::class);
        $revisionService->recordAndQueue(new EloquentCollection([$revisionEvent]));
        $revisionService->recordAndQueue(new EloquentCollection([$revisionEvent]));

        $this->assertTrue($faculty->notifications()->get()->contains(
            fn ($n): bool => (string) data_get($n->data, 'title') === 'Class schedule update',
        ));
        $this->assertTrue($studentUser->notifications()->get()->contains(
            fn ($n): bool => (string) data_get($n->data, 'title') === 'Class schedule update',
        ));
        $this->assertSame(1, $faculty->notifications()->count());

        // 2) Grade roster returned -> Faculty DB notification.
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(5)->toDateString(),
            'cutoff_at' => '17:00:00',
        ]);
        app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'ASSIGN-NTF-1');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar);
        $row = $roster->rows()->sole();
        app(SaveGradeRosterDraft::class)->execute($roster, [
            ['id' => $row->id, 'final_result' => '1.75', 'inc_completion_note' => null],
        ], $roster->lock_version, $roster->membership_signature, $faculty);
        $submittedRoster = app(SubmitGradeRoster::class)->execute($roster->fresh(), $faculty);
        $submittedRoster->forceFill(['state' => GradeRoster::StateReturned])->save();

        app(AcademicRecordNotificationService::class)->recordRosterReturnedAfterCommit($submittedRoster->fresh());
        $this->assertTrue($faculty->notifications()->get()->contains(
            fn ($n): bool => (string) data_get($n->data, 'title') === 'Grade roster returned for correction',
        ));

        // 3) Applicant resubmitted requested corrections -> active Registrar DB notification.
        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => $term->id,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
            'correction_closes_at' => now()->addDays(2),
        ]);
        $cycle->programs()->attach($program, ['accepts_first_year' => true, 'accepts_transferee' => true]);
        $set = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionApplication::PathFirstYear,
            'version' => 1,
            'state' => AdmissionRequirementSet::StateDraft,
        ]);
        AdmissionRequirement::factory()->for($set, 'requirementSet')->create(['requires_preliminary_evidence' => false]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $applicant = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $applicant->assignRole('applicant');

        $appData = [
            'application_path' => AdmissionApplication::PathFirstYear,
            'program_id' => $program->id,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => 'Elena',
            'last_name' => 'Cruz',
            'birth_date' => now()->subYears(20)->toDateString(),
            'citizenship_country_code' => 'PH',
            'phone' => '09171234567',
            'current_city_municipality' => 'Makati',
            'current_province' => 'Metro Manila',
            'prior_school_name' => 'Makati Science High School',
            'prior_school_country_code' => 'PH',
            'prior_school_completion_year' => now()->year - 1,
            'lrn_availability' => 'Provided',
            'lrn' => '123456789012',
            'privacy_acknowledged' => true,
            'accuracy_declared' => true,
        ];
        $draftApp = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $appData);
        $submittedApp = app(SubmitAdmissionApplication::class)->execute($draftApp, $applicant, $set->id);
        app(AdmissionReviewGuard::class)->start($submittedApp, $registrar, $submittedApp->current_submission_version_id);
        app(RequestAdmissionCorrection::class)->execute(
            $submittedApp,
            $registrar,
            [['type' => ApplicationCorrectionItem::ScopeField, 'key' => 'phone', 'admission_requirement_id' => null]],
            'Update mobile contact number.',
            'Applicant',
            now()->addHour(),
        );
        app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            ['phone' => '09179998877'],
            application: $submittedApp->fresh(),
        );
        app(SubmitAdmissionApplication::class)->execute($submittedApp->fresh(), $applicant, $set->id);

        $this->assertTrue($registrar->notifications()->get()->contains(
            fn ($n): bool => (string) data_get($n->data, 'title') === 'Applicant resubmitted corrections',
        ));

        // 4 & 5) Payment receipt submitted -> Accounting notification; Payment posted -> Student + pending-enrollment Registrar notifications.
        $pendingTerm = Term::factory()->create([
            'label' => '2nd Semester 2026-2027',
            'starts_on' => now()->addMonths(4)->toDateString(),
            'ends_on' => now()->addMonths(8)->toDateString(),
            'state' => Term::StateActive,
        ]);
        $pendingEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'credential_user_id' => $studentUser->id,
            'term_id' => $pendingTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $pendingEnrollment->id,
            'credential_user_id' => $studentUser->id,
            'term_id' => $pendingTerm->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $pendingEnrollment->id,
            'term_account_id' => $account->id,
            'source_proposal_version_id' => null,
            'total' => '12000.00',
            'subtotal' => '12000.00',
        ]);
        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'code' => 'ENROLLMENT',
            'label' => 'Enrollment obligation',
            'purpose' => 'Enrollment',
            'amount' => '12000.00',
            'due_at' => now()->subDay(),
            'required_for_enrollment' => true,
        ]);
        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [[
                    'bank_name' => 'Land Bank of the Philippines',
                    'branch' => 'Main',
                    'account_name' => 'Servitech Institute Asia',
                    'account_number' => '0011-2233-44',
                ]],
            ],
            'e_wallets' => [
                'enabled' => true,
                'accounts' => [[
                    'provider' => 'GCash',
                    'account_name' => 'Servitech Institute Asia Cashier',
                    'account_number' => '0917 123 4567',
                ]],
            ],
            'cash' => [
                'enabled' => true,
                'location' => 'Cashier Window 1, Administration Building',
            ],
        ], $accounting);

        $receipt = UploadedFile::fake()->image('receipt.png', 20, 20);
        app(SubmitPaymentEvidence::class)->execute(
            $account,
            $studentUser,
            $receipt,
            '5000.00',
            'bank_transfer',
            now()->subHour(),
            'BANK-NTF-'.Str::upper(Str::random(4)),
        );

        $this->assertTrue($accounting->notifications()->get()->contains(
            fn ($n): bool => (string) data_get($n->data, 'title') === 'New payment receipt submitted',
        ));

        $payment = Payment::factory()->create([
            'term_account_id' => $account->id,
            'student_profile_id' => $studentProfile->id,
            'term_id' => $pendingTerm->id,
            'state' => Payment::StatePosted,
            'amount' => '5000.00',
            'channel' => 'bank_transfer',
            'paid_at' => now()->subHour(),
            'provider_reference' => 'POSTED-NTF-001',
        ]);

        app(PaymentPostedNotificationService::class)->record($payment);

        $this->assertTrue($studentUser->notifications()->get()->contains(
            fn ($n): bool => (string) data_get($n->data, 'title') === 'Payment posted',
        ));
        $this->assertTrue($registrar->notifications()->get()->contains(
            fn ($n): bool => (string) data_get($n->data, 'title') === 'Payment verified for pending enrollment',
        ));
    }

    #[Test]
    public function term_calendar_empty_state_renders_schematic_and_timetable_uses_unified_stage_bar(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create([
            'state' => Term::StateDraft,
            'starts_on' => '2027-02-02',
            'ends_on' => '2027-02-21',
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertOk()
            ->assertSeeHtml('tala-calendar-facts')
            ->assertSee('30-minute intervals')
            ->assertSee('Mon–Sat · 7:00 AM–7:00 PM')
            ->assertSee('Feb 2 – Feb 21, 2027')
            ->assertDontSeeHtml('tala-calendar-schematic')
            ->assertDontSeeHtml('fi-empty-state');

        $timetable = Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertOk()
            ->assertDontSeeHtml('fi-page-sub-navigation-tabs')
            ->assertDontSeeHtml('tala-timetable-step-rail');

        $this->assertSame(1, substr_count($timetable->html(), 'wire:partial="action-modals"'));
    }

    private function staff(string $roleName): User
    {
        return $this->createSchedulingStaff(
            $roleName,
            $roleName === User::StaffRoleRegistrar ? ['manage-schedules', 'approve-documents', 'manage-admission-setup'] : [],
            verified: true,
        );
    }
}
