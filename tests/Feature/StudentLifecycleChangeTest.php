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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
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
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
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
        $row = $this->gradeRow($target, $fixture, 'P', GradeRosterRow::CategoryPending, released: false);
        $meetingCount = SectionMeeting::query()->count();
        $registrar = $this->registrar();
        $data = $this->baseData($fixture, StudentLifecycleChange::TypeSubjectDrop) + [
            'course_enrollment_id' => $target->id,
            'finance_adjustment' => -500,
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
        $this->assertSame(1, LedgerEntry::query()->where('source_type', StudentLifecycleChange::class)->where('source_id', $change->id)->count());
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
            ->assertSee('Review Operational Impact')
            ->assertSee('2 subject enrollment(s)')
            ->assertSee('Published master schedule stays unchanged');

        if (filled($subjectCode)) {
            $component->assertSee($subjectCode);
        }

        $this->assertSame($before, StudentLifecycleChange::query()->count());

        $component
            ->call('create')
            ->assertHasFormErrors(['impact_confirmed' => 'accepted']);
        $this->assertSame($before, StudentLifecycleChange::query()->count());

        $component
            ->fillForm(['impact_confirmed' => true])
            ->fillForm(['reason' => 'Updated approved withdrawal reason'])
            ->call('create')
            ->assertHasFormErrors(['impact_confirmed' => 'accepted']);
        $this->assertSame($before, StudentLifecycleChange::query()->count());

        $component
            ->fillForm(['impact_confirmed' => true])
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
            ->assertSee('Impact preview unavailable')
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
            'effective_on' => $futureTerm->starts_on->toDateString(),
            'finance_adjustment' => 1500,
            'target_program_id' => $targetProgram->id,
            'target_curriculum_version_id' => $targetCurriculum->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
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
            'accepted missing source grade' => [...$validData, 'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]]],
            'accepted invalid grade scale' => [...$validData, 'credit_entries' => [[
                'curriculum_entry_id' => $targetEntry->id,
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
            'effective_on' => $futureTerm->starts_on->toDateString(),
            'expected_return_term_id' => $returnTerm->id,
        ];

        $this->assertTrue(app(StudentLifecycleService::class)->preview($futureLeaveData)['cor_available_after']);

        app(StudentLifecycleService::class)->record($futureLeaveData, $registrar);
        $this->assertSame(StudentProfile::LifecycleActive, $futureLeave['profile']->fresh()->lifecycle_status);
        $this->assertSame($activeBindings, StudentScheduleBinding::query()->whereIn('course_enrollment_id', collect($futureLeave['courses'])->pluck('id'))->where('is_active', true)->count());
        $this->assertDatabaseHas('holds', ['student_profile_id' => $futureLeave['profile']->id, 'term_id' => $futureTerm->id, 'blocking_level' => 'blocks_enrollment']);

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
            'finance_adjustment' => -50,
        ], $this->registrar());

        $this->assertSame(1, $assessment->lines()->count());
        $this->assertSame('50.00', $assessment->fresh()->subtotal);
        $this->assertSame('50.00', $assessment->fresh()->total);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', StudentLifecycleChange::class)->where('source_id', $change->id)->count());
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

    private function gradeRow(CourseEnrollment $course, array $fixture, string $code, string $category, bool $released): GradeRosterRow
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
