<?php

namespace Tests\Feature;

use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\TranscriptPreview;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationClassChoices;
use App\Actions\Enrollment\RegistrationSelectionBasisResolver;
use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\ShiftProgramAndStartEnrollment;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Models\AcademicDecision;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationCreditLine;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ShiftProgramAndStartEnrollmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        config([
            'institution.address' => 'Synthetic Servitech Campus, Philippines',
            'institution.public.support_phone' => '0947 737 9208',
        ]);
        Mail::fake();
    }

    #[Test]
    public function enrollment_window_opens_program_shift_and_orchestrator_atomically_applies_enrolls_and_notifies_once(): void
    {
        $fixture = $this->baseShiftFixture();
        $registrar = $this->registrar();
        Cache::put(IrregularStudentReasons::ReviewCountCacheKey, 5, 60);

        $service = app(StudentLifecycleService::class);
        $this->assertTrue($service->isWindowOpen($fixture['futureTerm'], StudentLifecycleChange::TypeProgramShift));

        $data = [
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Approved shift to target program.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $fixture['targetEntry']->id,
                'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]],
        ];

        $preview = $service->preview($data);
        $this->assertFalse($preview['pending_application']);
        $this->assertNull($preview['enrollment_id']);

        $result = app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll($data, $registrar, applyAndEnroll: true);

        $this->assertTrue($result['applied']);
        $this->assertSame(StudentLifecycleChange::StateApplied, $result['change']->state);
        $this->assertNull($result['change']->enrollment_id);
        $this->assertNotNull($result['change']->applicationAudit);
        $this->assertSame('program_shift_applied', $result['change']->applicationAudit->event);
        $this->assertInstanceOf(Enrollment::class, $result['enrollment']);
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $result['enrollment']->selection_basis);
        $this->assertSame('RegistrarAssisted', $result['enrollment']->start_method);
        $this->assertSame($fixture['targetProgram']->id, $fixture['student']->fresh()->program_id);
        $this->assertSame($fixture['targetCurriculum']->id, $fixture['student']->fresh()->curriculum_version_id);
        $this->assertFalse(Cache::has(IrregularStudentReasons::ReviewCountCacheKey));

        // No spurious Accounting review for Program Shift
        $this->assertDatabaseMissing('operational_events', [
            'event_type' => OperationalEvent::TypeLifecycleAccountingReview,
            'related_record_type' => StudentLifecycleChange::class,
            'related_record_id' => $result['change']->id,
        ]);

        // Only the APPLIED notification is recorded (RECORDED_APPROVED email suppressed)
        $notifications = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeAcademicProgressLifecycleEmail)
            ->where('related_record_type', StudentLifecycleChange::class)
            ->where('related_record_id', $result['change']->id)
            ->pluck('external_id')
            ->all();
        $this->assertCount(1, $notifications);
        $this->assertStringEndsWith(':state:APPLIED', $notifications[0]);
    }

    #[Test]
    public function orchestrator_rolls_back_completely_when_enrollment_start_preconditions_fail(): void
    {
        $fixture = $this->baseShiftFixture();
        $registrar = $this->registrar();
        $originalProgramId = $fixture['student']->program_id;
        $data = [
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Approved shift blocked by hold.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [],
        ];

        // 1. Blocking enrollment hold rolls back everything
        $hold = Hold::factory()->create([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeAcademicDeficit,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);

        try {
            app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll($data, $registrar, applyAndEnroll: true);
            $this->fail('Expected active blocking hold to roll back shift and enrollment.');
        } catch (ValidationException) {
            $this->assertSame($originalProgramId, $fixture['student']->fresh()->program_id);
            $this->assertDatabaseMissing('student_lifecycle_changes', ['student_profile_id' => $fixture['student']->id, 'type' => StudentLifecycleChange::TypeProgramShift]);
            $this->assertDatabaseMissing('enrollments', ['student_profile_id' => $fixture['student']->id, 'term_id' => $fixture['futureTerm']->id]);
        }
        $hold->update(['status' => Hold::StatusResolved]);

        // 2. Window applicability restricted to StandardContinuing rolls back everything
        $fixture['futureTerm']->update(['registration_applicability' => 'StandardContinuing']);
        try {
            app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll($data, $registrar, applyAndEnroll: true);
            $this->fail('Expected window applicability mismatch to roll back shift and enrollment.');
        } catch (ValidationException) {
            $this->assertSame($originalProgramId, $fixture['student']->fresh()->program_id);
            $this->assertDatabaseMissing('student_lifecycle_changes', ['student_profile_id' => $fixture['student']->id, 'type' => StudentLifecycleChange::TypeProgramShift]);
        }
        $fixture['futureTerm']->update(['registration_applicability' => 'AllEligible']);

        // 3. Conflicting InProgress same-term enrollment rolls back everything
        $inProgress = Enrollment::factory()->create([
            'student_profile_id' => $fixture['student']->id,
            'credential_user_id' => $fixture['student']->user_id,
            'term_id' => $fixture['futureTerm']->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'officially_enrolled_at' => null,
            'status' => 'pending_review',
        ]);
        try {
            app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll($data, $registrar, applyAndEnroll: true);
            $this->fail('Expected conflicting InProgress enrollment to roll back shift.');
        } catch (StudentLifecycleRuleViolation) {
            $this->assertSame($originalProgramId, $fixture['student']->fresh()->program_id);
            $this->assertDatabaseMissing('student_lifecycle_changes', ['student_profile_id' => $fixture['student']->id, 'type' => StudentLifecycleChange::TypeProgramShift]);
        }
        $inProgress->delete();

        // 4. Closed enrollment window rolls back everything
        TermCalendarWindow::query()->whereHas('package', fn ($q) => $q->where('term_id', $fixture['futureTerm']->id))->delete();
        try {
            app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll($data, $registrar, applyAndEnroll: true);
            $this->fail('Expected closed enrollment window to roll back shift.');
        } catch (CalendarGateViolation) {
            $this->assertSame($originalProgramId, $fixture['student']->fresh()->program_id);
            $this->assertDatabaseMissing('student_lifecycle_changes', ['student_profile_id' => $fixture['student']->id, 'type' => StudentLifecycleChange::TypeProgramShift]);
        }
    }

    #[Test]
    public function orchestrator_supports_record_only_and_applying_an_existing_recorded_shift(): void
    {
        $fixture = $this->baseShiftFixture();
        $registrar = $this->registrar();
        $orchestrator = app(ShiftProgramAndStartEnrollment::class);

        $recorded = $orchestrator->recordAndOptionallyEnroll([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Record first, apply and enroll later.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [],
        ], $registrar, applyAndEnroll: false);

        $this->assertFalse($recorded['applied']);
        $this->assertNull($recorded['enrollment']);
        $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $recorded['change']->state);

        $applied = $orchestrator->applyExistingAndEnroll($recorded['change'], $registrar);

        $this->assertTrue($applied['applied']);
        $this->assertSame($recorded['change']->id, $applied['change']->id);
        $this->assertSame(StudentLifecycleChange::StateApplied, $applied['change']->state);
        $this->assertInstanceOf(Enrollment::class, $applied['enrollment']);
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $applied['enrollment']->selection_basis);
    }

    #[Test]
    public function program_shift_validates_noop_draft_curriculum_lifecycle_official_enrollment_duplicate_source_and_preterm_supersession(): void
    {
        $fixture = $this->baseShiftFixture();
        $registrar = $this->registrar();
        $service = app(StudentLifecycleService::class);
        $secondTargetEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $fixture['targetCurriculum']->id]);
        $draftCurriculum = CurriculumVersion::factory()->create([
            'program_id' => $fixture['targetProgram']->id,
            'state' => CurriculumVersion::StateDraft,
        ]);

        $base = [
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Program shift validation test.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [],
        ];

        // 1. No-op shift to same (program_id, curriculum_version_id)
        try {
            $service->record([
                ...$base,
                'target_program_id' => $fixture['student']->program_id,
                'target_curriculum_version_id' => $fixture['student']->curriculum_version_id,
            ], $registrar);
            $this->fail('Expected no-op shift to be rejected.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('different program or curriculum', $exception->getMessage());
        }

        // 2. Non-active target curriculum
        try {
            $service->record([...$base, 'target_curriculum_version_id' => $draftCurriculum->id], $registrar);
            $this->fail('Expected draft target curriculum to be rejected.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('must be active', $exception->getMessage());
        }

        // 3. Duplicate source_grade_outcome_event_id across TreatmentAccepted rows
        try {
            $service->record([
                ...$base,
                'credit_entries' => [
                    [
                        'curriculum_entry_id' => $fixture['targetEntry']->id,
                        'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                        'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                    ],
                    [
                        'curriculum_entry_id' => $secondTargetEntry->id,
                        'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                        'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                    ],
                ],
            ], $registrar);
            $this->fail('Expected duplicate source grade outcome event to be rejected.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('cannot be credited to multiple', $exception->getMessage());
        }

        // 4. Blocked lifecycle status
        $fixture['student']->update(['lifecycle_status' => StudentProfile::LifecycleWithdrawn]);
        try {
            $service->record($base, $registrar);
            $this->fail('Expected withdrawn student to be rejected from shifting.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('lifecycle status', $exception->getMessage());
        }
        $fixture['student']->update(['lifecycle_status' => StudentProfile::LifecycleActive]);

        // 5. Existing OfficiallyEnrolled enrollment in target term
        $officialInTarget = Enrollment::factory()->create([
            'student_profile_id' => $fixture['student']->id,
            'credential_user_id' => $fixture['student']->user_id,
            'term_id' => $fixture['futureTerm']->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
            'status' => 'officially_enrolled',
        ]);
        try {
            $service->record($base, $registrar);
            $this->fail('Expected shift targeting an officially enrolled term to be rejected.');
        } catch (StudentLifecycleRuleViolation $exception) {
            $this->assertStringContainsString('already officially enrolled', $exception->getMessage());
        }
        $officialInTarget->delete();

        // 6. Pre-term applied shift does NOT block a current-term lifecycle action
        $applied = app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll($base, $registrar, applyAndEnroll: true)['change'];
        $this->assertSame(StudentLifecycleChange::StateApplied, $applied->state);

        $currentTermPreview = $service->preview([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['currentTerm']->id,
            'type' => StudentLifecycleChange::TypeInactivation,
            'effective_on' => today('Asia/Manila')->toDateString(),
            'reason' => 'Current term lifecycle action should remain allowed.',
        ], checkWindow: false);
        $this->assertSame(StudentLifecycleChange::TypeInactivation, $currentTermPreview['type']);
    }

    #[Test]
    public function matching_sia_credit_suggestions_separates_identical_and_equivalent_passed_courses(): void
    {
        $fixture = $this->baseShiftFixture();
        $passedCourse = $fixture['sourceSpec']->course;

        // Identical target entry using the exact same course_id
        $identicalSpec = CourseSpecification::factory()->create(['course_id' => $passedCourse->id]);
        $identicalEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $fixture['targetCurriculum']->id,
            'course_specification_id' => $identicalSpec->id,
        ]);

        // Add a second passed course to match by equivalency (without reusing the first source event)
        $secondSourceSpec = CourseSpecification::factory()->create();
        $secondSourceEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'course_specification_id' => $secondSourceSpec->id,
        ]);
        $secondSourceEvent = $this->addPassedCourse($fixture['currentEnrollment'], $fixture['currentTerm'], $secondSourceEntry, '1.75');

        CourseRequirement::factory()->create([
            'course_specification_id' => $fixture['targetEntry']->course_specification_id,
            'related_course_id' => $secondSourceSpec->course_id,
            'rule_type' => CourseRequirement::TypeEquivalency,
            'group_key' => 'eq1',
            'state' => CourseRequirement::StateActive,
        ]);

        $suggestions = app(StudentLifecycleService::class)->matchingSiaCreditSuggestions(
            $fixture['student'],
            $fixture['targetCurriculum'],
        );

        $this->assertCount(1, $suggestions['already_satisfied']);
        $this->assertSame($identicalEntry->id, $suggestions['already_satisfied'][0]['curriculum_entry_id']);
        $this->assertSame($fixture['sourceEvent']->id, $suggestions['already_satisfied'][0]['source_grade_outcome_event_id']);

        $this->assertCount(1, $suggestions['equivalent_matches']);
        $this->assertSame($fixture['targetEntry']->id, $suggestions['equivalent_matches'][0]['curriculum_entry_id']);
        $this->assertSame($secondSourceEvent->id, $suggestions['equivalent_matches'][0]['source_grade_outcome_event_id']);
        $this->assertCount(2, $suggestions['available_sources']);
    }

    #[Test]
    public function shift_credited_subjects_are_excluded_from_choices_blocked_by_eligibility_and_satisfy_prerequisites(): void
    {
        $fixture = $this->baseShiftFixture();
        $registrar = $this->registrar();

        $dependentSpec = CourseSpecification::factory()->create([
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $dependentEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $fixture['targetCurriculum']->id,
            'course_specification_id' => $dependentSpec->id,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $dependentSpec->id,
            'related_course_id' => $fixture['targetEntry']->courseSpecification->course_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'p1',
            'state' => CourseRequirement::StateActive,
        ]);

        $fixture['targetEntry']->courseSpecification->update([
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);

        $creditedOffering = TermOffering::factory()->create([
            'term_id' => $fixture['futureTerm']->id,
            'curriculum_entry_id' => $fixture['targetEntry']->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $creditedSection = Section::factory()->create([
            'term_offering_id' => $creditedOffering->id,
            'state' => Section::StateOpen,
        ]);

        $dependentOffering = TermOffering::factory()->create([
            'term_id' => $fixture['futureTerm']->id,
            'curriculum_entry_id' => $dependentEntry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $dependentSection = Section::factory()->create([
            'term_offering_id' => $dependentOffering->id,
            'state' => Section::StateOpen,
        ]);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $fixture['futureTerm']->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', fake()->uuid()),
            'solver_version' => 'test',
            'published_at' => now(),
            'publication_version' => 1,
        ]);

        PublishedTimetableVersion::factory()->create([
            'term_id' => $fixture['futureTerm']->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_at' => now(),
            'published_by' => $registrar->id,
        ]);

        $result = app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Shift with accepted credit.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $fixture['targetEntry']->id,
                'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]],
        ], $registrar, applyAndEnroll: true);

        $choices = app(RegistrationClassChoices::class)->forCase($result['enrollment']);
        $sectionIds = $choices['sections']->pluck('id')->all();
        $this->assertNotContains($creditedSection->id, $sectionIds);
        $this->assertContains($dependentSection->id, $sectionIds);

        $eligibility = app(RegistrationAcademicEligibilityQuery::class);
        // Dependent course passes prerequisite check via shift-credited course
        $this->assertTrue($eligibility->passes($result['enrollment'], $fixture['targetCurriculum'], collect([$dependentOffering])));
        // Shift-credited course itself is blocked from re-enrollment
        $this->assertFalse($eligibility->passes($result['enrollment'], $fixture['targetCurriculum'], collect([$creditedOffering])));
    }

    #[Test]
    public function transcript_preview_preserves_prior_transfer_credits_and_does_not_double_count_shift_credit_units(): void
    {
        $fixture = $this->baseShiftFixture();
        $registrar = $this->registrar();

        // Prior transfer credit on original curriculum
        $transferSpec = CourseSpecification::factory()->create([
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $transferEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'course_specification_id' => $transferSpec->id,
        ]);
        $evaluation = RegistrationCreditEvaluation::factory()->create([
            'enrollment_id' => $fixture['currentEnrollment']->id,
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'credited_curriculum_entry_ids' => [$transferEntry->id],
        ]);
        RegistrationCreditLine::factory()->create([
            'registration_credit_evaluation_id' => $evaluation->id,
            'curriculum_entry_id' => $transferEntry->id,
            'source_course_code' => 'HIST-1',
            'tor_grade' => '1.50',
        ]);

        // Shift to new program with accepted internal credit from $fixture['sourceEvent'] (3 units)
        app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Shift preserving transfer credit without double-counting earned units.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [[
                'curriculum_entry_id' => $fixture['targetEntry']->id,
                'source_grade_outcome_event_id' => $fixture['sourceEvent']->id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]],
        ], $registrar, applyAndEnroll: true);

        $conferral = app(RecordDegreeConferral::class)->execute(
            $fixture['student']->fresh(),
            $registrar,
            $fixture['targetProgram']->name,
            today()->toDateString(),
            'SYNTH-SHIFT-CONFERRAL',
        );
        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral,
            $registrar,
            'EXT-SHIFT-TOR',
            today()->toDateString(),
            'Synthetic Registrar',
            'College Registrar',
            TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Seal box.',
        );

        $preview = app(TranscriptPreview::class)->forRequest($request);
        $rows = collect($preview['academic_years'])->flatten(1);

        // Prior transfer credit survives after curriculum_version_id changed
        $transferRow = $rows->firstWhere('attempt_or_credit', 'Transfer credit');
        $this->assertNotNull($transferRow);
        $this->assertSame($transferSpec->course->code, $transferRow['course_code']);

        // Earned units = 3.00 (transfer) + 3.00 (original released SIA course) + 0.00 (internal shift credit referencing same event) = 6.00
        $lastSummary = $rows->filter(fn (array $row): bool => $row['term_summary'] !== null)->last()['term_summary'];
        $this->assertSame('6.00', $lastSummary['cumulative_earned_units']);
    }

    #[Test]
    public function shiftee_irregular_reasons_and_selection_basis_handle_prior_keep_regular_needs_review_and_later_term_enrollment(): void
    {
        $fixture = $this->baseShiftFixture();
        $registrar = $this->registrar();

        // Older pre-shift "Keep regular" decision
        AcademicDecision::query()->create([
            'student_profile_id' => $fixture['student']->id,
            'effect' => AcademicDecision::EffectAllowed,
            'reason' => 'Old pre-shift keep regular decision.',
            'effective_from' => today()->subMonth()->toDateString(),
            'state' => AcademicDecision::StateActive,
            'recorded_by' => $registrar->id,
            'recorded_at' => now()->subDays(10),
        ]);

        // Shift for Term 2 (futureTerm)
        $result = app(ShiftProgramAndStartEnrollment::class)->recordAndOptionallyEnroll([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['futureTerm']->id,
            'effective_on' => $fixture['futureTerm']->starts_on->toDateString(),
            'reason' => 'Shifted to new program.',
            'target_program_id' => $fixture['targetProgram']->id,
            'target_curriculum_version_id' => $fixture['targetCurriculum']->id,
            'credit_entries' => [],
        ], $registrar, applyAndEnroll: true);

        $student = $fixture['student']->fresh();
        $reasonsService = app(IrregularStudentReasons::class);

        // Older pre-shift Keep regular does NOT suppress Status: Irregular - Shiftee
        $reasons = $reasonsService->forStudent($student);
        $this->assertTrue($reasons['is_irregular']);
        $this->assertSame('Irregular – Shiftee', $reasons['status_label']);
        // Registrar-applied shift is already confirmed (not flagged in unreviewed queue)
        $this->assertFalse($reasonsService->needsReview($student));

        // Shifting for Term 2 and enrolling in Term 3 still routes to IndividuallyAdvised
        $term3 = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => $fixture['futureTerm']->ends_on->copy()->addMonth(),
            'ends_on' => $fixture['futureTerm']->ends_on->copy()->addMonths(5),
        ]);
        $this->assertSame(
            Enrollment::SelectionIndividuallyAdvised,
            app(RegistrationSelectionBasisResolver::class)->forContinuingStudent($student, $term3),
        );

        // Once officially enrolled in the shift term, Shiftee reason clears even if effective_on > term.starts_on
        $result['enrollment']->update([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $afterEnrollment = $reasonsService->forStudent($student);
        $this->assertFalse($afterEnrollment['is_irregular']);
    }

    /** @return array<string, mixed> */
    private function baseShiftFixture(): array
    {
        $student = StudentProfile::factory()->create(['lifecycle_status' => StudentProfile::LifecycleActive]);
        $student->user->assignRole('student');

        $currentTerm = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => today()->subMonths(2),
            'ends_on' => today()->addWeeks(2),
        ]);
        $currentEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $currentTerm->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => today()->subMonths(2),
        ]);

        $sourceSpec = CourseSpecification::factory()->create([
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $sourceEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id,
            'course_specification_id' => $sourceSpec->id,
        ]);
        $sourceEvent = $this->addPassedCourse($currentEnrollment, $currentTerm, $sourceEntry, '2.00');

        $futureTerm = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => today()->addMonth(),
            'ends_on' => today()->addMonths(5),
            'registration_applicability' => 'AllEligible',
        ]);
        $package = TermCalendarPackage::factory()->create([
            'term_id' => $futureTerm->id,
            'state' => TermCalendarPackage::StateActive,
        ]);
        TermCalendarWindow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => today()->subDay(),
            'closes_on' => today()->addWeek(),
        ]);

        $targetProgram = Program::factory()->create();
        $targetCurriculum = CurriculumVersion::factory()->create([
            'program_id' => $targetProgram->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $targetSpec = CourseSpecification::factory()->create([
            'credit_units' => 3.00,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $targetEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $targetCurriculum->id,
            'course_specification_id' => $targetSpec->id,
        ]);

        return compact(
            'student',
            'currentTerm',
            'currentEnrollment',
            'sourceSpec',
            'sourceEntry',
            'sourceEvent',
            'futureTerm',
            'targetProgram',
            'targetCurriculum',
            'targetEntry',
        );
    }

    private function addPassedCourse(Enrollment $enrollment, Term $term, CurriculumEntry $entry, string $grade): GradeOutcomeEvent
    {
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create([
            'term_offering_id' => $offering->id,
            'state' => Section::StateOpen,
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => $entry->courseSpecification->credit_units,
            'added_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'state' => GradeRoster::StateReleased,
            'released_at' => now(),
        ]);
        $row = GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $grade,
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'released_at' => now(),
        ]);

        return GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id,
            'result_code' => $grade,
            'new_value' => $grade,
            'released_at' => now(),
        ]);
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }
}
