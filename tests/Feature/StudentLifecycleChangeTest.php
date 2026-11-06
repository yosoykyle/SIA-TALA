<?php

namespace Tests\Feature;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Resources\StudentLifecycleChanges\Pages\CreateStudentLifecycleChange;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\FeeRule;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentLifecycleChangeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function subject_drop_atomically_releases_only_student_records_posts_idempotent_effects_and_preserves_master_schedule(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], 'subject_drop');
        $target = $fixture['courses'][0];
        $row = $this->gradeRow($target, $fixture, null, GradeRosterRow::CategoryPending, released: false);
        $meetingCount = SectionMeeting::query()->count();
        $registrar = $this->registrar();
        $data = $this->baseData($fixture, StudentLifecycleChange::TypeSubjectDrop) + [
            'course_enrollment_id' => $target->id,
        ];

        $change = app(StudentLifecycleService::class)->record($data, $registrar);
        $again = app(StudentLifecycleService::class)->record($data, $registrar);

        $this->assertSame($change->id, $again->id);
        $this->assertSame(CourseEnrollment::StatusDropped, $target->fresh()->status);
        $this->assertFalse((bool) $target->scheduleBindings()->first()->is_active);
        $this->assertSame(EnrollmentSeatReservation::StatusReleased, $target->seatReservations()->first()->status);
        $this->assertSame('DRP', $row->fresh()->current_outcome_code);
        $this->assertDatabaseHas('grade_outcome_events', ['grade_roster_row_id' => $row->id, 'event_type' => GradeOutcomeEvent::TypeLifecycleOutcome]);
        $this->assertSame($meetingCount, SectionMeeting::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('source_type', StudentLifecycleChange::class)->where('source_id', $change->id)->count());
        $this->assertDatabaseHas('operational_events', [
            'event_type' => OperationalEvent::TypeLifecycleAccountingReview,
            'related_record_type' => StudentLifecycleChange::class,
            'related_record_id' => $change->id,
            'status' => OperationalEvent::StatusReviewRequired,
        ]);
        $this->assertDatabaseHas('operational_events', [
            'event_type' => OperationalEvent::TypeAcademicProgressLifecycleEmail,
            'related_record_type' => StudentLifecycleChange::class,
            'related_record_id' => $change->id,
        ]);
        $this->assertSame('officially_enrolled', $fixture['enrollment']->fresh()->status);

        $this->expectException(RuntimeException::class);
        app(StudentLifecycleService::class)->record($this->baseData($fixture, StudentLifecycleChange::TypeSubjectDrop) + [
            'course_enrollment_id' => $fixture['courses'][1]->id,
        ], $registrar);
    }

    #[Test]
    public function withdrawal_releases_all_courses_changes_status_and_rolls_back_when_window_validation_fails(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $registrar = $this->registrar();
        $before = StudentLifecycleChange::query()->count();

        try {
            app(StudentLifecycleService::class)->record($this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal), $registrar);
            $this->fail('Expected missing-window validation failure.');
        } catch (RuntimeException) {
            $this->assertSame($before, StudentLifecycleChange::query()->count());
            $this->assertSame(2, CourseEnrollment::query()->where('enrollment_id', $fixture['enrollment']->id)->where('status', CourseEnrollment::StatusActive)->count());
        }

        $this->window($fixture['term'], 'withdrawal');
        $change = app(StudentLifecycleService::class)->record($this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal), $registrar);
        $this->assertSame(StudentLifecycleChange::StateApplied, $change->state);
        $this->assertSame('withdrawn', $fixture['enrollment']->fresh()->status);
        $this->assertSame(StudentProfile::LifecycleWithdrawn, $fixture['profile']->fresh()->lifecycle_status);
        $this->assertSame(0, StudentScheduleBinding::query()->whereIn('course_enrollment_id', collect($fixture['courses'])->pluck('id'))->where('is_active', true)->count());
    }

    #[Test]
    public function lifecycle_create_requires_reviewed_impact_before_it_writes_the_approved_result(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], 'withdrawal');
        $registrar = $this->registrar();
        $before = StudentLifecycleChange::query()->count();
        $data = $this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal);
        $subjectCode = $fixture['courses'][0]
            ->termOffering
            ->course()
            ?->code;

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm($data)
            ->assertSee('Review effects')
            ->assertSee('2 in this term')
            ->assertSee('Released grades and published timetable stay unchanged');

        if (filled($subjectCode)) {
            $component->assertSee($subjectCode);
        }

        $this->assertSame($before, StudentLifecycleChange::query()->count());
        $this->assertTrue($component->instance()->recordingActions()[1]->isDisabled());

        $component
            ->call('create')
            ->assertHasFormErrors(['impact_confirmed' => 'accepted']);
        $this->assertSame($before, StudentLifecycleChange::query()->count());

        $component
            ->fillForm(['impact_confirmed' => true])
            ->fillForm(['reason' => 'Updated approved withdrawal reason']);
        $this->assertTrue($component->instance()->recordingActions()[1]->isDisabled());
        $component
            ->call('create')
            ->assertHasFormErrors(['impact_confirmed' => 'accepted']);
        $this->assertSame($before, StudentLifecycleChange::query()->count());

        $component->fillForm(['impact_confirmed' => true]);
        $this->assertFalse($component->instance()->recordingActions()[1]->isDisabled());
        $component
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertSame($before + 1, StudentLifecycleChange::query()->count());
        $change = StudentLifecycleChange::query()->latest('id')->firstOrFail();
        $this->assertCount(2, data_get($change->impact_snapshot, 'affected_subjects', []));
        $this->assertSame(0, data_get($change->impact_snapshot, 'master_schedule_changes'));
    }

    #[Test]
    public function lifecycle_create_rejects_an_unavailable_preview_with_plain_language_and_no_partial_write(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $registrar = $this->registrar();
        $before = StudentLifecycleChange::query()->count();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm($this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal))
            ->assertSee('Complete the details to review')
            ->fillForm(['impact_confirmed' => true])
            ->call('create')
            ->assertHasFormErrors(['impact_confirmed']);

        $this->assertSame($before, StudentLifecycleChange::query()->count());
        $this->assertSame('officially_enrolled', $fixture['enrollment']->fresh()->status);
    }

    #[Test]
    public function lifecycle_business_rule_failures_use_the_dedicated_domain_exception(): void
    {
        $fixture = $this->enrollmentFixture(1);

        $this->expectException(StudentLifecycleRuleViolation::class);

        app(StudentLifecycleService::class)
            ->preview($this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal));
    }

    #[Test]
    public function lifecycle_create_does_not_disguise_unexpected_system_failures_as_validation(): void
    {
        $registrar = $this->registrar();
        $unexpected = new LogicException('Unexpected lifecycle infrastructure failure.');
        $service = $this->createMock(StudentLifecycleService::class);
        $service->expects($this->once())
            ->method('record')
            ->willThrowException($unexpected);
        $this->app->instance(StudentLifecycleService::class, $service);
        $this->actingAs($registrar);

        $page = new class extends CreateStudentLifecycleChange
        {
            /** @param array<string, mixed> $data */
            public function invokeRecordCreation(array $data): StudentLifecycleChange
            {
                return $this->handleRecordCreation($data);
            }
        };

        $this->expectExceptionObject($unexpected);

        $page->invokeRecordCreation([]);
    }

    #[Test]
    public function current_term_leave_releases_current_schedule_and_marks_profile_on_leave(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], 'leave_of_absence');
        $returnTerm = Term::factory()->create(['starts_on' => today()->addMonths(5), 'ends_on' => today()->addMonths(8)]);

        $data = [
            ...$this->baseData($fixture, StudentLifecycleChange::TypeLeaveOfAbsence),
            'expected_return_term_id' => $returnTerm->id,
        ];

        $this->assertFalse(app(StudentLifecycleService::class)->preview($data)['cor_available_after']);

        app(StudentLifecycleService::class)->record($data, $this->registrar());

        $this->assertSame('withdrawn', $fixture['enrollment']->fresh()->status);
        $this->assertSame(StudentProfile::LifecycleLeaveOfAbsence, $fixture['profile']->fresh()->lifecycle_status);
        $this->assertSame(0, StudentScheduleBinding::query()->whereIn('course_enrollment_id', collect($fixture['courses'])->pluck('id'))->where('is_active', true)->count());
        $this->assertSame(0, EnrollmentSeatReservation::query()->whereIn('course_enrollment_id', collect($fixture['courses'])->pluck('id'))->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->count());

        $cor = app(BuildCorOutput::class)->forStudent($fixture['profile']->user);
        $this->assertFalse($cor['available']);
        $this->assertStringContainsString('No current official enrollment is available for COR viewing.', $cor['reason']);
    }

    #[Test]
    public function registrar_recorded_inactive_changes_only_profile_lifecycle_status(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], 'inactivation');
        $activeBindingCount = StudentScheduleBinding::query()
            ->whereIn('course_enrollment_id', collect($fixture['courses'])->pluck('id'))
            ->where('is_active', true)
            ->count();

        $change = app(StudentLifecycleService::class)->record(
            $this->baseData($fixture, StudentLifecycleChange::TypeInactivation),
            $this->registrar(),
        );

        $this->assertSame(StudentLifecycleChange::StateApplied, $change->state);
        $this->assertSame(StudentProfile::LifecycleInactive, $fixture['profile']->fresh()->lifecycle_status);
        $this->assertSame('officially_enrolled', $fixture['enrollment']->fresh()->status);
        $this->assertSame($activeBindingCount, StudentScheduleBinding::query()->whereIn('course_enrollment_id', collect($fixture['courses'])->pluck('id'))->where('is_active', true)->count());
        $this->assertSame(0, LedgerEntry::query()->where('source_type', StudentLifecycleChange::class)->where('source_id', $change->id)->count());
    }

    #[Test]
    public function future_program_shift_records_a_credit_checklist_then_applies_separately_without_current_schedule_changes(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $futureTerm = Term::factory()->create([
            'starts_on' => today()->addMonth(),
            'ends_on' => today()->addMonths(5),
            'state' => Term::StateDraft,
        ]);
        $this->window($futureTerm, 'program_shift');
        $targetProgram = Program::factory()->create();
        $targetCurriculum = CurriculumVersion::factory()->create(['program_id' => $targetProgram->id]);
        $targetEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $targetCurriculum->id]);
        $registrar = $this->registrar();
        $bindingCount = StudentScheduleBinding::query()->where('is_active', true)->count();
        $ledgerCount = LedgerEntry::query()->count();
        Hold::factory()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $futureTerm->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeAcademicDeficit,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $data = [
            ...$this->baseData($fixture, StudentLifecycleChange::TypeProgramShift),
            'term_id' => $futureTerm->id,
            'enrollment_id' => null,
            'effective_on' => $futureTerm->starts_on->copy()->addWeek()->toDateString(),
            'finance_adjustment' => 1500,
            'target_program_id' => $targetProgram->id,
            'target_curriculum_version_id' => $targetCurriculum->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
                'source_grade_outcome_event_id' => $this->releasedShiftSource($fixture, '2.00')->id,
                'source_course_id' => $targetEntry->courseSpecification->course_id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                'numeric_grade' => '2.00',
            ]],
        ];

        $change = app(StudentLifecycleService::class)->record($data, $registrar);
        $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $change->state);
        $this->assertSame($fixture['profile']->program_id, $fixture['profile']->fresh()->program_id);
        $this->assertSame($bindingCount, StudentScheduleBinding::query()->where('is_active', true)->count());
        $this->assertSame($ledgerCount, LedgerEntry::query()->count());
        $impactSnapshot = $change->getAttribute('impact_snapshot');
        $this->assertIsArray($impactSnapshot);
        $this->assertSame($fixture['profile']->program_id, data_get($impactSnapshot, 'program_before.id'));
        $this->assertSame($targetProgram->id, data_get($impactSnapshot, 'program_after.id'));
        $this->assertSame($targetCurriculum->id, data_get($impactSnapshot, 'curriculum_version_after.id'));
        $this->assertSame(1, data_get($impactSnapshot, 'active_hold_count'));
        $this->assertSame('none', data_get($impactSnapshot, 'finance_effect.mode'));
        $this->assertDatabaseHas('program_shift_credit_entries', ['student_lifecycle_change_id' => $change->id, 'curriculum_entry_id' => $targetEntry->id]);

        try {
            app(StudentLifecycleService::class)->applyProgramShift($change, $registrar);
            $this->fail('Expected future-term application to be rejected.');
        } catch (RuntimeException) {
            $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $change->fresh()->state);
        }

        $this->travelTo($futureTerm->starts_on->copy()->addDay());
        try {
            app(StudentLifecycleService::class)->applyProgramShift($change, $registrar);
            $this->fail('Expected application before the recorded effective date to be rejected.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('effective date', $exception->getMessage());
            $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $change->fresh()->state);
            $this->assertSame($fixture['profile']->program_id, $fixture['profile']->fresh()->program_id);
        }

        $this->travelTo($change->effective_on->copy()->addDay());
        $applied = app(StudentLifecycleService::class)->applyProgramShift($change, $registrar);
        $this->assertSame(StudentLifecycleChange::StateApplied, $applied->state);
        $this->assertSame($targetProgram->id, $fixture['profile']->fresh()->program_id);
        $this->assertSame($targetCurriculum->id, $fixture['profile']->fresh()->curriculum_version_id);
        $this->assertSame($bindingCount, StudentScheduleBinding::query()->where('is_active', true)->count());
    }

    #[Test]
    public function program_shift_rejects_mismatched_target_curriculum_invalid_credit_rows_and_current_terms(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $futureTerm = Term::factory()->create([
            'starts_on' => today()->addMonth(),
            'ends_on' => today()->addMonths(5),
        ]);
        $this->window($futureTerm, 'program_shift');
        $this->window($fixture['term'], 'program_shift');
        $targetProgram = Program::factory()->create();
        $targetCurriculum = CurriculumVersion::factory()->create(['program_id' => $targetProgram->id]);
        $targetEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $targetCurriculum->id]);
        $otherCurriculum = CurriculumVersion::factory()->create();
        $outsideEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $otherCurriculum->id]);
        $registrar = $this->registrar();
        $validData = [
            ...$this->baseData($fixture, StudentLifecycleChange::TypeProgramShift),
            'term_id' => $futureTerm->id,
            'enrollment_id' => null,
            'effective_on' => $futureTerm->starts_on->toDateString(),
            'target_program_id' => $targetProgram->id,
            'target_curriculum_version_id' => $targetCurriculum->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
                'source_grade_outcome_event_id' => $this->releasedShiftSource($fixture, '2.25')->id,
                'source_course_id' => $targetEntry->courseSpecification->course_id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                'numeric_grade' => '2.25',
            ]],
        ];

        $cases = [
            'current term' => [...$validData, 'term_id' => $fixture['term']->id],
            'mismatched curriculum program' => [...$validData, 'target_curriculum_version_id' => $otherCurriculum->id],
            'outside curriculum entry' => [...$validData, 'credit_entries' => [[
                'curriculum_entry_id' => $outsideEntry->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentDeficient,
            ]]],
            'duplicate curriculum entry' => [...$validData, 'credit_entries' => [
                [
                    'curriculum_entry_id' => $targetEntry->id,
                    'treatment' => ProgramShiftCreditEntry::TreatmentDeficient,
                ],
                [
                    'curriculum_entry_id' => $targetEntry->id,
                    'treatment' => ProgramShiftCreditEntry::TreatmentRejected,
                ],
            ]],
            'accepted missing released result' => [...$validData, 'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]]],
            'accepted invalid source result' => [...$validData, 'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
                'source_grade_outcome_event_id' => PHP_INT_MAX,
                'source_course_id' => $targetEntry->courseSpecification->course_id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                'numeric_grade' => '6.00',
            ]]],
        ];

        foreach ($cases as $case => $data) {
            $before = StudentLifecycleChange::query()->count();

            try {
                app(StudentLifecycleService::class)->record($data, $registrar);
                $this->fail('Expected invalid Program Shift case to fail: '.$case);
            } catch (RuntimeException) {
                $this->assertSame($before, StudentLifecycleChange::query()->count(), $case);
            }
        }
    }

    #[Test]
    public function recorded_program_shift_can_be_cancelled_before_application_without_profile_mutation(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $futureTerm = Term::factory()->create([
            'starts_on' => today()->addMonth(),
            'ends_on' => today()->addMonths(5),
        ]);
        $this->window($futureTerm, 'program_shift');
        $targetProgram = Program::factory()->create();
        $targetCurriculum = CurriculumVersion::factory()->create(['program_id' => $targetProgram->id]);
        $targetEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $targetCurriculum->id]);
        $registrar = $this->registrar();
        $originalProgramId = $fixture['profile']->program_id;
        $originalCurriculumId = $fixture['profile']->curriculum_version_id;

        $change = app(StudentLifecycleService::class)->record([
            ...$this->baseData($fixture, StudentLifecycleChange::TypeProgramShift),
            'term_id' => $futureTerm->id,
            'enrollment_id' => null,
            'effective_on' => $futureTerm->starts_on->toDateString(),
            'target_program_id' => $targetProgram->id,
            'target_curriculum_version_id' => $targetCurriculum->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentDeficient,
            ]],
        ], $registrar);

        $cancelled = app(StudentLifecycleService::class)->cancelProgramShift($change, $registrar);

        $this->assertSame(StudentLifecycleChange::StateCancelled, $cancelled->state);
        $this->assertSame($originalProgramId, $fixture['profile']->fresh()->program_id);
        $this->assertSame($originalCurriculumId, $fixture['profile']->fresh()->curriculum_version_id);

        $this->travelTo($futureTerm->starts_on->copy()->addDay());
        $this->expectException(RuntimeException::class);
        app(StudentLifecycleService::class)->applyProgramShift($change, $registrar);
    }

    #[Test]
    public function leave_transfer_and_reactivation_apply_only_their_authorized_effects(): void
    {
        $registrar = $this->registrar();
        $futureLeave = $this->enrollmentFixture(2);
        $futureTerm = Term::factory()->create(['starts_on' => today()->addMonth(), 'ends_on' => today()->addMonths(4)]);
        $returnTerm = Term::factory()->create(['starts_on' => today()->addMonths(5), 'ends_on' => today()->addMonths(8)]);
        $this->window($futureTerm, 'leave_of_absence');
        $activeBindings = StudentScheduleBinding::query()->whereIn('course_enrollment_id', collect($futureLeave['courses'])->pluck('id'))->where('is_active', true)->count();
        $futureLeaveData = [
            ...$this->baseData($futureLeave, StudentLifecycleChange::TypeLeaveOfAbsence),
            'term_id' => $futureTerm->id,
            'enrollment_id' => null,
            'effective_on' => $futureTerm->starts_on->toDateString(),
            'expected_return_term_id' => $returnTerm->id,
        ];

        $futureLeavePreview = app(StudentLifecycleService::class)->preview($futureLeaveData);
        $this->assertFalse($futureLeavePreview['cor_available_after']);
        $this->assertSame(StudentProfile::LifecycleActive, $futureLeavePreview['profile_status_after']);
        $this->assertTrue($futureLeavePreview['pending_application']);
        $this->assertSame($futureTerm->starts_on->toDateString(), $futureLeavePreview['application_available_on']);

        $recordedFutureLeave = app(StudentLifecycleService::class)->record($futureLeaveData, $registrar);
        $this->assertSame('Waiting for effective date', $recordedFutureLeave->statusLabel());
        $this->assertSame(StudentProfile::LifecycleActive, $futureLeave['profile']->fresh()->lifecycle_status);
        $this->assertSame(StudentProfile::LifecycleActive, $recordedFutureLeave->impact_snapshot['profile_status_after']);
        $this->assertSame($activeBindings, StudentScheduleBinding::query()->whereIn('course_enrollment_id', collect($futureLeave['courses'])->pluck('id'))->where('is_active', true)->count());
        $this->assertDatabaseMissing('holds', ['student_profile_id' => $futureLeave['profile']->id, 'source_type' => StudentLifecycleChange::class, 'source_id' => $recordedFutureLeave->id]);

        $transfer = $this->enrollmentFixture(2);
        $this->window($transfer['term'], 'transfer_out');
        app(StudentLifecycleService::class)->record($this->baseData($transfer, StudentLifecycleChange::TypeTransferOut), $registrar);
        $this->assertSame(StudentProfile::LifecycleTransferredOut, $transfer['profile']->fresh()->lifecycle_status);
        $this->assertSame(0, StudentScheduleBinding::query()->whereIn('course_enrollment_id', collect($transfer['courses'])->pluck('id'))->where('is_active', true)->count());

        $reactivationProfile = StudentProfile::factory()->create(['lifecycle_status' => StudentProfile::LifecycleArchived, 'archived_at' => now()]);
        $reactivationTerm = Term::factory()->create();
        $this->window($reactivationTerm, 'reactivation');
        app(StudentLifecycleService::class)->record([
            ...$this->baseData($transfer, StudentLifecycleChange::TypeReactivation),
            'student_profile_id' => $reactivationProfile->id,
            'term_id' => $reactivationTerm->id,
            'enrollment_id' => null,
        ], $registrar);
        $this->assertSame(StudentProfile::LifecycleActive, $reactivationProfile->fresh()->lifecycle_status);
        $this->assertNull($reactivationProfile->fresh()->archived_at);
        $this->assertSame(0, Enrollment::query()->where('student_profile_id', $reactivationProfile->id)->where('term_id', $reactivationTerm->id)->count());
    }

    #[Test]
    public function future_leave_preview_preserves_subjects_and_schedule_already_recorded_for_that_term(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $fixture['term']->update(['starts_on' => today()->addMonth(), 'ends_on' => today()->addMonths(4)]);
        $returnTerm = Term::factory()->create(['starts_on' => today()->addMonths(5), 'ends_on' => today()->addMonths(8)]);
        $this->window($fixture['term'], 'leave_of_absence');
        $data = [
            ...$this->baseData($fixture, StudentLifecycleChange::TypeLeaveOfAbsence),
            'effective_on' => $fixture['term']->starts_on->toDateString(),
            'expected_return_term_id' => $returnTerm->id,
        ];
        $service = app(StudentLifecycleService::class);

        $preview = $service->preview($data);
        $this->assertSame([], $preview['course_enrollment_ids']);
        $this->assertSame([], $preview['binding_ids']);
        $this->assertSame([], $preview['reservation_ids']);

        $service->record($data, $this->registrar());
        foreach ($fixture['courses'] as $course) {
            $this->assertSame(CourseEnrollment::StatusActive, $course->fresh()->status);
            $this->assertTrue($course->scheduleBindings()->firstOrFail()->is_active);
        }
    }

    #[Test]
    #[TestWith([StudentLifecycleChange::TypeLeaveOfAbsence, 'leave_of_absence', StudentProfile::LifecycleLeaveOfAbsence])]
    #[TestWith([StudentLifecycleChange::TypeWithdrawal, 'withdrawal', StudentProfile::LifecycleWithdrawn])]
    #[TestWith([StudentLifecycleChange::TypeTransferOut, 'transfer_out', StudentProfile::LifecycleTransferredOut])]
    #[TestWith([StudentLifecycleChange::TypeReactivation, 'reactivation', StudentProfile::LifecycleActive])]
    #[TestWith([StudentLifecycleChange::TypeInactivation, 'inactivation', StudentProfile::LifecycleInactive])]
    public function future_approvals_wait_and_apply_once_on_the_effective_date(string $type, string $window, string $expectedStatus): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
        $fixture = $this->enrollmentFixture(2);
        if ($type === StudentLifecycleChange::TypeReactivation) {
            $fixture['profile']->update(['lifecycle_status' => StudentProfile::LifecycleLeaveOfAbsence]);
        }
        $before = $fixture['profile']->lifecycle_status;
        $this->window($fixture['term'], $window);
        $returnTerm = Term::factory()->create(['starts_on' => '2027-06-01', 'ends_on' => '2027-10-31']);
        $service = app(StudentLifecycleService::class);
        $registrar = $this->registrar();
        $change = $service->record([
            ...$this->baseData($fixture, $type),
            'effective_on' => '2026-11-10',
            'expected_return_term_id' => $type === StudentLifecycleChange::TypeLeaveOfAbsence ? $returnTerm->id : null,
        ], $registrar);
        $original = $change->impact_snapshot;

        $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $change->state);
        $this->assertSame($before, $fixture['profile']->fresh()->lifecycle_status);
        $this->assertDatabaseMissing('operational_events', ['external_id' => "lifecycle-accounting-review:{$change->id}"]);
        $this->assertDatabaseMissing('holds', ['source_type' => StudentLifecycleChange::class, 'source_id' => $change->id]);
        try {
            $service->apply($change, $registrar);
            $this->fail('A future approval must not apply early.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('before its effective date', $exception->getMessage());
        }

        $this->travelTo(Carbon::parse('2026-11-10 00:00:00', 'Asia/Manila'));
        $applied = $service->apply($change, $registrar);
        $this->assertSame(StudentLifecycleChange::StateApplied, $applied->state);
        $this->assertSame($expectedStatus, $fixture['profile']->fresh()->lifecycle_status);
        $this->assertSame($original, $applied->impact_snapshot);
        $this->assertSame($expectedStatus, $applied->applicationAudit->properties->get('application_effects')['profile_status_after']);
        $service->apply($change, $registrar);
        $this->assertSame(1, DB::table('activity_log')->where('subject_type', StudentLifecycleChange::class)->where('subject_id', $change->id)->where('event', 'student_lifecycle_change_applied')->count());
        $this->assertSame(1, OperationalEvent::query()->where('external_id', "lifecycle-accounting-review:{$change->id}")->count());
    }

    #[Test]
    public function a_cancelled_future_approval_creates_no_effects_and_cannot_be_applied(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $this->window($fixture['term'], 'withdrawal');
        $registrar = $this->registrar();
        $service = app(StudentLifecycleService::class);
        $change = $service->record([...$this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal), 'effective_on' => today()->addDays(5)->toDateString()], $registrar);
        $service->cancel($change, $registrar);
        $service->cancel($change, $registrar);
        $this->assertSame(StudentLifecycleChange::StateCancelled, $change->fresh()->state);
        $this->assertSame(StudentProfile::LifecycleActive, $fixture['profile']->fresh()->lifecycle_status);
        $this->assertSame(CourseEnrollment::StatusActive, $fixture['courses'][0]->fresh()->status);
        $this->assertDatabaseMissing('operational_events', ['external_id' => "lifecycle-accounting-review:{$change->id}"]);
        $this->expectException(StudentLifecycleRuleViolation::class);
        $service->apply($change, $registrar);
    }

    #[Test]
    public function expected_return_cannot_be_the_same_or_an_earlier_term(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $this->window($fixture['term'], 'leave_of_absence');
        $this->expectException(StudentLifecycleRuleViolation::class);
        $this->expectExceptionMessage('Expected return term must start after');
        app(StudentLifecycleService::class)->record([
            ...$this->baseData($fixture, StudentLifecycleChange::TypeLeaveOfAbsence),
            'expected_return_term_id' => $fixture['term']->id,
        ], $this->registrar());
    }

    #[Test]
    public function application_rejects_changed_sources_and_preserves_newer_student_status(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $this->window($fixture['term'], 'withdrawal');
        $service = app(StudentLifecycleService::class);
        $registrar = $this->registrar();
        $change = $service->record([...$this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal), 'effective_on' => today()->addDays(5)->toDateString()], $registrar);
        $fixture['profile']->update(['lifecycle_status' => StudentProfile::LifecycleTransferredOut]);
        $this->travel(6)->days();
        try {
            $service->apply($change, $registrar);
            $this->fail('An approval with changed sources must not apply.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('student record changed', $exception->getMessage());
        }
        $this->assertSame(StudentProfile::LifecycleTransferredOut, $fixture['profile']->fresh()->lifecycle_status);
        $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $change->fresh()->state);
        $this->assertDatabaseMissing('activity_log', ['subject_type' => StudentLifecycleChange::class, 'subject_id' => $change->id, 'event' => 'student_lifecycle_change_applied']);
    }

    #[Test]
    public function applying_a_future_approval_rechecks_grades_released_since_recording(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], 'withdrawal');
        $service = app(StudentLifecycleService::class);
        $registrar = $this->registrar();
        $change = $service->record([...$this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal), 'effective_on' => today()->addDays(5)->toDateString()], $registrar);
        $row = $this->gradeRow($fixture['courses'][0], $fixture, '2.00', GradeRosterRow::CategoryPassing, released: true);
        $this->travel(6)->days();

        $applied = $service->apply($change, $registrar);

        $this->assertSame('2.00', $row->fresh()->current_outcome_code);
        $this->assertSame(CourseEnrollment::StatusActive, $fixture['courses'][0]->fresh()->status);
        $this->assertSame(CourseEnrollment::StatusWithdrawn, $fixture['courses'][1]->fresh()->status);
        $this->assertSame([$fixture['courses'][1]->id], $applied->applicationAudit->properties->get('application_effects')['course_enrollment_ids']);
        $this->assertSame([], $applied->impact_snapshot['course_enrollment_ids']);
    }

    #[Test]
    public function an_older_effective_change_cannot_replace_a_later_applied_event(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $this->window($fixture['term'], 'withdrawal');
        StudentLifecycleChange::factory()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'state' => StudentLifecycleChange::StateApplied,
            'effective_on' => today(),
        ]);
        $this->expectException(StudentLifecycleRuleViolation::class);
        $this->expectExceptionMessage('later lifecycle change is already applied');
        app(StudentLifecycleService::class)->record([
            ...$this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal),
            'effective_on' => today()->subDay()->toDateString(),
        ], $this->registrar());
    }

    #[Test]
    public function a_later_same_date_event_stales_an_approval_even_when_the_profile_values_match(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $this->window($fixture['term'], 'withdrawal');
        $service = app(StudentLifecycleService::class);
        $registrar = $this->registrar();
        $change = $service->record([...$this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal), 'effective_on' => today()->addDays(5)->toDateString()], $registrar);
        StudentLifecycleChange::factory()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'type' => StudentLifecycleChange::TypeReactivation,
            'state' => StudentLifecycleChange::StateApplied,
            'effective_on' => $change->effective_on,
        ]);
        $this->travel(6)->days();
        $this->expectException(StudentLifecycleRuleViolation::class);
        $this->expectExceptionMessage('newer lifecycle change is already applied');
        $service->apply($change, $registrar);
    }

    #[Test]
    public function subject_drop_rejects_a_final_released_grade_and_rolls_back_all_effects(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], 'subject_drop');
        $target = $fixture['courses'][0];
        $this->gradeRow($target, $fixture, '2.00', GradeRosterRow::CategoryPassing, released: true);
        $binding = $target->scheduleBindings()->firstOrFail();

        try {
            app(StudentLifecycleService::class)->record($this->baseData($fixture, StudentLifecycleChange::TypeSubjectDrop) + [
                'course_enrollment_id' => $target->id,
            ], $this->registrar());
            $this->fail('Expected final released-grade rejection.');
        } catch (RuntimeException) {
            $this->assertSame(CourseEnrollment::StatusActive, $target->fresh()->status);
            $this->assertTrue((bool) $binding->fresh()->is_active);
            $this->assertSame(0, StudentLifecycleChange::query()->where('student_profile_id', $fixture['profile']->id)->count());
        }
    }

    #[Test]
    #[TestWith([StudentLifecycleChange::TypeWithdrawal, 'withdrawal'])]
    #[TestWith([StudentLifecycleChange::TypeLeaveOfAbsence, 'leave_of_absence'])]
    #[TestWith([StudentLifecycleChange::TypeTransferOut, 'transfer_out'])]
    public function full_lifecycle_changes_preserve_released_courses_and_only_withdraw_unreleased_courses(string $type, string $window): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], $window);
        $releasedCourse = $fixture['courses'][0];
        $unreleasedCourse = $fixture['courses'][1];
        $releasedRow = $this->gradeRow($releasedCourse, $fixture, '2.00', GradeRosterRow::CategoryPassing, released: true);
        $unreleasedRow = $this->gradeRow($unreleasedCourse, $fixture, '1.75', GradeRosterRow::CategoryPassing, released: false);
        $returnTerm = Term::factory()->create(['starts_on' => today()->addMonths(5), 'ends_on' => today()->addMonths(8)]);
        $data = $this->baseData($fixture, $type) + ['expected_return_term_id' => $returnTerm->id];
        $service = app(StudentLifecycleService::class);

        $this->assertSame([$unreleasedCourse->id], $service->preview($data)['course_enrollment_ids']);
        $change = $service->record($data, $this->registrar());

        $this->assertSame(CourseEnrollment::StatusActive, $releasedCourse->fresh()->status);
        $this->assertSame('2.00', $releasedRow->fresh()->current_outcome_code);
        $this->assertSame(CourseEnrollment::StatusWithdrawn, $unreleasedCourse->fresh()->status);
        $this->assertSame('W', $unreleasedRow->fresh()->current_outcome_code);
        $this->assertSame([$unreleasedCourse->id], $change->impact_snapshot['course_enrollment_ids']);
        $this->assertDatabaseMissing('grade_outcome_events', ['grade_roster_row_id' => $releasedRow->id, 'event_type' => GradeOutcomeEvent::TypeLifecycleOutcome]);
        $this->assertDatabaseHas('grade_outcome_events', ['grade_roster_row_id' => $unreleasedRow->id, 'event_type' => GradeOutcomeEvent::TypeLifecycleOutcome]);
    }

    #[Test]
    #[TestWith(['preview'])]
    #[TestWith(['record'])]
    public function lifecycle_rejects_an_enrollment_from_another_term_before_effects(string $operation): void
    {
        $fixture = $this->enrollmentFixture(1);
        $otherTerm = Term::factory()->create();
        $this->window($otherTerm, 'withdrawal');
        $data = [...$this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal), 'term_id' => $otherTerm->id];
        $service = app(StudentLifecycleService::class);
        $registrar = $this->registrar();
        $this->expectException(StudentLifecycleRuleViolation::class);
        $this->expectExceptionMessage('Choose an enrollment from the selected term.');

        try {
            if ($operation === 'preview') {
                $service->preview($data);
            } else {
                $service->record($data, $registrar);
            }
        } finally {
            $this->assertSame(CourseEnrollment::StatusActive, $fixture['courses'][0]->fresh()->status);
            $this->assertSame(StudentProfile::LifecycleActive, $fixture['profile']->fresh()->lifecycle_status);
            $this->assertDatabaseMissing('student_lifecycle_changes', ['student_profile_id' => $fixture['profile']->id]);
        }
    }

    #[Test]
    public function lifecycle_finance_regenerates_a_draft_assessment_instead_of_posting_a_ledger_mutation(): void
    {
        $fixture = $this->enrollmentFixture(2);
        $this->window($fixture['term'], 'subject_drop');
        $assessment = Assessment::query()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'version' => 1,
            'state' => Assessment::StateDraft,
            'currency' => 'PHP',
            'subtotal' => 100,
            'discount_total' => 0,
            'total' => 100,
            'required_downpayment' => 100,
        ]);
        $rule = FeeRule::query()->create([
            'code' => 'LIFECYCLE-TEST',
            'name' => 'Lifecycle Test Fee',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryOther,
            'program_id' => $fixture['profile']->program_id,
            'term_id' => $fixture['term']->id,
            'calculation_type' => FeeRule::CalculationFixed,
            'amount' => '50.00',
            'effective_from' => today()->toDateString(),
            'is_active' => true,
            'authority' => 'TAL-73B fixture',
        ]);
        foreach ($fixture['courses'] as $index => $course) {
            AssessmentLine::query()->create([
                'assessment_id' => $assessment->id,
                'fee_rule_id' => $rule->id,
                'course_enrollment_id' => $course->id,
                'source_line_key' => 'course-'.$course->id,
                'description_snapshot' => 'Course fee '.$index,
                'quantity' => 1,
                'rate' => 50,
                'amount' => 50,
                'line_type' => 'COURSE',
            ]);
        }

        $change = app(StudentLifecycleService::class)->record($this->baseData($fixture, StudentLifecycleChange::TypeSubjectDrop) + [
            'course_enrollment_id' => $fixture['courses'][0]->id,
        ], $this->registrar());

        $this->assertSame(2, $assessment->lines()->count());
        $this->assertSame('100.00', $assessment->fresh()->subtotal);
        $this->assertSame('100.00', $assessment->fresh()->total);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', StudentLifecycleChange::class)->where('source_id', $change->id)->count());
        $this->assertDatabaseHas('operational_events', [
            'event_type' => OperationalEvent::TypeLifecycleAccountingReview,
            'related_record_id' => $change->id,
            'status' => OperationalEvent::StatusReviewRequired,
        ]);
    }

    #[Test]
    public function changing_student_discards_the_previous_context_and_confirmation(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $this->actingAs($this->registrar());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $before = StudentLifecycleChange::query()->count();

        Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm($this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal))
            ->fillForm(['impact_confirmed' => true])
            ->call('changeStudent')
            ->assertFormSet(['student_profile_id' => null, 'term_id' => null, 'type' => null, 'reason' => null, 'impact_confirmed' => false])
            ->assertSee('Choose a student')
            ->assertDontSee('Review effects');

        $this->assertSame($before, StudentLifecycleChange::query()->count());
    }

    #[Test]
    public function routine_change_derives_enrollment_and_records_without_external_approval_metadata(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $this->window($fixture['term'], 'withdrawal');
        $actor = $this->registrar();
        $data = $this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal);
        unset($data['enrollment_id'], $data['authority'], $data['decided_on']);
        $data['private_source_reference'] = 'Actual optional office reference';

        $change = app(StudentLifecycleService::class)->record($data, $actor);

        $this->assertSame($fixture['enrollment']->id, $change->enrollment_id);
        $this->assertNull($change->authority);
        $this->assertNull($change->decided_on);
        $this->assertSame('Actual optional office reference', $change->private_source_reference);
        $this->assertSame($actor->id, $change->recorded_by);
        $this->assertNotNull($change->created_at);
        $this->assertSame(CourseEnrollment::StatusWithdrawn, $fixture['courses'][0]->fresh()->status);
    }

    #[Test]
    public function explicit_enrollment_from_another_student_is_rejected_before_effects(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $other = $this->enrollmentFixture(0);
        $this->window($fixture['term'], 'withdrawal');
        $data = $this->baseData($fixture, StudentLifecycleChange::TypeWithdrawal);
        $data['enrollment_id'] = $other['enrollment']->id;
        $before = StudentLifecycleChange::query()->count();
        try {
            app(StudentLifecycleService::class)->record($data, $this->registrar());
            $this->fail('The other student enrollment must be rejected.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('selected student', $exception->getMessage());
        }
        $this->assertSame($before, StudentLifecycleChange::query()->count());
        $this->assertSame(CourseEnrollment::StatusActive, $fixture['courses'][0]->fresh()->status);
    }

    /** @return array{profile:StudentProfile,term:Term,enrollment:Enrollment,courses:list<CourseEnrollment>} */
    private function enrollmentFixture(int $courseCount): array
    {
        $profile = StudentProfile::factory()->create(['lifecycle_status' => StudentProfile::LifecycleActive]);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', fake()->uuid()),
            'solver_version' => 'test',
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $courses = [];

        for ($index = 0; $index < $courseCount; $index++) {
            $entry = CurriculumEntry::factory()->create(['curriculum_version_id' => $profile->curriculum_version_id]);
            $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id]);
            $section = Section::factory()->create(['term_offering_id' => $offering->id]);
            $group = SectionDeliveryGroup::factory()->create(['section_id' => $section->id]);
            $demand = SchedulingDemand::factory()->create(['term_offering_id' => $offering->id, 'section_delivery_group_id' => $group->id]);
            $faculty = User::factory()->create(['status' => User::StatusActive]);
            $meeting = SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $index + 1,
                'faculty_user_id' => $faculty->id,
                'day_of_week' => $index + 1,
                'starts_at' => '08:00',
                'ends_at' => '09:00',
                'modality' => TermOffering::ModalityOnline,
                'state' => SectionMeeting::StateActive,
                'published_at' => now(),
            ]);
            $course = CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => 3,
                'added_at' => now(),
            ]);
            StudentScheduleBinding::query()->create([
                'course_enrollment_id' => $course->id,
                'section_meeting_id' => $meeting->id,
                'is_active' => true,
                'effective_from' => today(),
                'source' => StudentScheduleBinding::SourceRegistrarPlacement,
            ]);
            EnrollmentSeatReservation::query()->create([
                'enrollment_id' => $enrollment->id,
                'course_enrollment_id' => $course->id,
                'section_id' => $section->id,
                'status' => EnrollmentSeatReservation::StatusActive,
                'reserved_at' => now(),
            ]);
            $courses[] = $course;
        }

        return compact('profile', 'term', 'enrollment', 'courses');
    }

    #[Test]
    public function accepted_shift_credits_are_bound_to_the_students_current_released_result(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $source = $this->releasedShiftSource($fixture, '2.00');
        $foreign = $this->releasedShiftSource($this->enrollmentFixture(1), '1.25');
        $unreleased = GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $source->grade_roster_row_id,
            'result_code' => '1.50', 'released_at' => null,
        ]);
        $incomplete = GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $source->grade_roster_row_id,
            'result_code' => 'INC', 'released_at' => now(),
        ]);
        $data = $this->shiftData($fixture);
        $targetEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $data['target_curriculum_version_id']]);
        $actor = $this->registrar();
        $before = StudentLifecycleChange::query()->count();

        foreach ([$foreign, $unreleased, $source, $incomplete] as $invalid) {
            try {
                app(StudentLifecycleService::class)->record([...$data, 'credit_entries' => [[
                    'curriculum_entry_id' => $targetEntry->id,
                    'source_grade_outcome_event_id' => $invalid->id,
                    'source_course_id' => $targetEntry->courseSpecification->course_id,
                    'numeric_grade' => '1.00',
                    'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                ]]], $actor);
                $this->fail('Expected an unavailable released source to be rejected.');
            } catch (StudentLifecycleRuleViolation $exception) {
                $this->assertStringContainsString('released passing result', $exception->getMessage());
                $this->assertSame($before, StudentLifecycleChange::query()->count());
            }
        }

        $current = GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $source->grade_roster_row_id,
            'result_code' => '2.25', 'released_at' => now(),
        ]);
        $change = app(StudentLifecycleService::class)->record([...$data, 'credit_entries' => [[
            'curriculum_entry_id' => $targetEntry->id,
            'source_grade_outcome_event_id' => $current->id,
            'source_course_id' => $targetEntry->courseSpecification->course_id,
            'numeric_grade' => '1.00',
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
        ]]], $actor);
        $credit = $change->programShiftCredits()->sole();
        $this->assertSame($current->id, $credit->source_grade_outcome_event_id);
        $this->assertSame($fixture['courses'][0]->termOffering->curriculumEntry->courseSpecification->course_id, $credit->source_course_id);
        $this->assertSame('2.2500', $credit->numeric_grade);
    }

    #[Test]
    public function reviewed_program_shift_can_record_and_apply_without_credit_rows(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $data = $this->shiftData($fixture);
        $actor = $this->registrar();
        $change = app(StudentLifecycleService::class)->record([...$data, 'credit_entries' => []], $actor);
        $this->assertSame(0, $change->programShiftCredits()->count());
        $this->travelTo(Carbon::parse($data['effective_on'])->addDay());
        app(StudentLifecycleService::class)->applyProgramShift($change, $actor);
        $this->assertSame($data['target_program_id'], $fixture['profile']->fresh()->program_id);
        $this->assertSame(StudentLifecycleChange::StateApplied, $change->fresh()->state);
        $this->assertSame(0, $change->programShiftCredits()->count());
    }

    #[Test]
    public function program_shift_application_rejects_a_credit_source_corrected_after_approval(): void
    {
        $fixture = $this->enrollmentFixture(1);
        $source = $this->releasedShiftSource($fixture, '2.00');
        $data = $this->shiftData($fixture);
        $targetEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $data['target_curriculum_version_id']]);
        $actor = $this->registrar();
        $change = app(StudentLifecycleService::class)->record([...$data, 'credit_entries' => [[
            'curriculum_entry_id' => $targetEntry->id,
            'source_grade_outcome_event_id' => $source->id,
            'source_course_id' => $fixture['courses'][0]->termOffering->curriculumEntry->courseSpecification->course_id,
            'numeric_grade' => '2.00',
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
        ]]], $actor);
        GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $source->grade_roster_row_id,
            'event_type' => GradeOutcomeEvent::TypePostedCorrection,
            'result_code' => '3.00', 'released_at' => now(),
        ]);
        $this->travelTo(Carbon::parse($data['effective_on'])->addDay());
        try {
            app(StudentLifecycleService::class)->applyProgramShift($change, $actor);
            $this->fail('Expected the superseded approval source to block application.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('released passing result', $exception->getMessage());
            $this->assertSame($fixture['profile']->program_id, $fixture['profile']->fresh()->program_id);
            $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $change->fresh()->state);
            $this->assertSame('2.0000', $change->programShiftCredits()->sole()->numeric_grade);
        }
    }

    private function releasedShiftSource(array $fixture, string $grade): GradeOutcomeEvent
    {
        $row = $this->gradeRow($fixture['courses'][0], $fixture, $grade, 'Passing', true);

        return GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id,
            'result_code' => $grade, 'new_value' => $grade, 'released_at' => now(),
        ]);
    }

    private function shiftData(array $fixture): array
    {
        $term = Term::factory()->create(['starts_on' => today()->addMonth(), 'ends_on' => today()->addMonths(5)]);
        $this->window($term, 'program_shift');
        $curriculum = CurriculumVersion::factory()->create();

        return [...$this->baseData($fixture, StudentLifecycleChange::TypeProgramShift),
            'term_id' => $term->id, 'enrollment_id' => null,
            'effective_on' => $term->starts_on->toDateString(),
            'target_program_id' => $curriculum->program_id, 'target_curriculum_version_id' => $curriculum->id,
        ];
    }

    private function gradeRow(CourseEnrollment $course, array $fixture, ?string $code, string $category, bool $released): GradeRosterRow
    {
        $section = Section::query()->where('term_offering_id', $course->term_offering_id)->firstOrFail();
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $course->term_offering_id,
            'section_id' => $section->id,
            'state' => $released ? GradeRoster::StateReleased : GradeRoster::StateDraft,
            'released_at' => $released ? now() : null,
        ]);

        return GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $course->id,
            'current_outcome_code' => $code,
            'current_outcome_category' => $category,
            'released_at' => $released ? now() : null,
        ]);
    }

    private function window(Term $term, string $key): void
    {
        CalendarEvent::factory()->create([
            'term_id' => $term->id,
            'process_key' => $key,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
    }

    /** @param array{profile:StudentProfile,term:Term,enrollment:Enrollment,courses:list<CourseEnrollment>} $fixture @return array<string,mixed> */
    private function baseData(array $fixture, string $type): array
    {
        return [
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'type' => $type,
            'requested_on' => today()->toDateString(),
            'effective_on' => today()->toDateString(),
            'decided_on' => today()->toDateString(),
            'authority' => 'Registrar Director',
            'private_source_reference' => 'APPROVAL-001',
            'reason' => 'Approved institutional lifecycle result.',
        ];
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }
}
