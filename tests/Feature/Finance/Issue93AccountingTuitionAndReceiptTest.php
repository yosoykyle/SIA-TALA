<?php

namespace Tests\Feature\Finance;

use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\FeePlans\Pages\ListFeePlans;
use App\Filament\Resources\GradeRosters\Tables\GradeRostersTable;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Assessment;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationCreditEvaluation;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue93AccountingTuitionAndReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'applicant',
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Role::findOrCreate(User::StaffRoleRegistrar, 'web')
            ->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
    }

    public function test_accounting_proof_review_renders_verify_and_return_buttons_inside_comparison_card(): void
    {
        Storage::fake('local');
        Mail::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        [$case, $program, $term, $curriculum, $section] = $this->advisedEnrollmentFixture(Enrollment::SelectionStandardCurriculum);

        $plan = app(CreateFeePlanDraft::class)->execute(
            $program,
            $term,
            [['code' => 'TUITION', 'label' => 'Tuition fee', 'amount' => '3000.00']],
            $accounting,
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment payment', 'purpose' => 'Enrollment', 'amount' => '3000.00', 'due_at' => now()->addDays(5)->format('Y-m-d H:i:s'), 'required_for_enrollment' => true]],
        );
        app(PublishFeePlan::class)->execute($plan, $accounting, 'SYN-PLAN-PROOF', CarbonImmutable::parse('2026-08-15'));

        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);
        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting, $proposal->id, $plan->fresh()->id);

        $obligation = $assessment->obligations()->firstOrFail();
        $applicant = $case->credentialUser;
        $this->enableDigitalChannels($accounting);
        app(SubmitPaymentEvidence::class)->execute(
            $assessment->termAccount,
            $applicant,
            UploadedFile::fake()->image('receipt.png'),
            '3000.00',
            'bank_transfer',
            now()->subHour(),
            'REF-93-PROOF',
            [$obligation->id],
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)
            ->withQueryParams(['view' => 'proof'])
            ->test(ViewEnrollment::class, ['record' => $case->id]);

        $html = $component->html();
        $cardStart = strpos($html, 'accounting-source-comparison');
        $footerPos = strpos($html, 'accounting-comparison-actions');
        $notePos = strpos($html, 'The receipt has not been recorded as a payment.');

        $this->assertNotFalse($cardStart);
        $this->assertNotFalse($footerPos);
        $this->assertNotFalse($notePos);
        $this->assertGreaterThan($cardStart, $footerPos);
        $this->assertLessThan($notePos, $footerPos);
        $component->assertSee('Verify payment')->assertSee('Reject receipt');
    }

    public function test_tuition_calculator_in_individual_assessment_prefills_enrolled_units_and_applies_tuition_charge(): void
    {
        Mail::fake();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        [$case, , , , $section] = $this->advisedEnrollmentFixture(Enrollment::SelectionIndividuallyAdvised, creditUnits: '4.0');

        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('recordIndividualAssessment');

        $this->assertSame('4', (string) $component->get('mountedActions.0.data.tuition_units'));

        $component
            ->setActionData([
                'tuition_rate_per_unit' => '1500',
                'tuition_units' => '4',
            ])
            ->assertMountedActionModalSee('Computed tuition: PHP 6,000.00 · Tuition Fee (4 units @ PHP 1,500.00/unit)')
            ->callAction(TestAction::make('applyTuitionToCharges')->schemaComponent(schema: 'mountedActionSchema0'));

        $charges = array_values($component->get('mountedActions.0.data.charges') ?? []);
        $this->assertCount(1, $charges);
        $this->assertSame('Tuition Fee (4 units @ PHP 1,500.00/unit)', $charges[0]['label']);
        $this->assertSame('6000.00', $charges[0]['amount']);

        $chargeKey = array_key_first($component->get('mountedActions.0.data.charges'));
        $component->setActionData([
            'authority_reference' => 'SYN-CALC-93',
            'authority_date' => '2026-08-20',
            'charges' => [$chargeKey => $charges[0]],
            'enrollment_payment' => ['amount' => '6000.00', 'due_at' => '2026-08-25 17:00'],
        ])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Assessment recorded');

        $assessment = Assessment::query()->where('enrollment_id', $case->id)->sole();
        $this->assertSame('6000.00', $assessment->total);
        $this->assertSame('Tuition Fee (4 units @ PHP 1,500.00/unit)', $assessment->lines()->sole()->description_snapshot);
    }

    public function test_tuition_calculator_in_fee_plan_form_applies_and_updates_tuition_row_without_duplication(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $program = Program::factory()->create(['is_active' => true]);
        $term = Term::factory()->create(['state' => Term::StateActive]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($accounting)
            ->test(ListFeePlans::class)
            ->mountAction('createDraft')
            ->setActionData([
                'program_id' => $program->id,
                'term_id' => $term->id,
                'tuition_rate_per_unit' => '1200',
                'tuition_units' => '18',
            ])
            ->assertMountedActionModalSee('Computed tuition: PHP 21,600.00 · Tuition Fee (18 units @ PHP 1,200.00/unit)')
            ->callAction(TestAction::make('applyTuitionToCharges')->schemaComponent(schema: 'mountedActionSchema0'));

        $charges = array_values($component->get('mountedActions.0.data.charges') ?? []);
        $this->assertCount(1, $charges);
        $this->assertSame('Tuition Fee (18 units @ PHP 1,200.00/unit)', $charges[0]['label']);
        $this->assertSame('21600.00', $charges[0]['amount']);

        $component
            ->setActionData([
                'tuition_rate_per_unit' => '1250',
                'tuition_units' => '18',
            ])
            ->callAction(TestAction::make('applyTuitionToCharges')->schemaComponent(schema: 'mountedActionSchema0'));

        $updatedCharges = array_values($component->get('mountedActions.0.data.charges') ?? []);
        $this->assertCount(1, $updatedCharges);
        $this->assertSame('Tuition Fee (18 units @ PHP 1,250.00/unit)', $updatedCharges[0]['label']);
        $this->assertSame('22500.00', $updatedCharges[0]['amount']);
    }

    public function test_view_enrollment_section_options_handle_or_groups_corequisites_earlier_pass_and_current_transferee_credits(): void
    {
        Mail::fake();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $program = Program::factory()->create(['is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $priorTerm = Term::factory()->create(['state' => Term::StateClosed, 'starts_on' => now()->subMonths(8)->toDateString(), 'ends_on' => now()->subMonths(4)->toDateString()]);
        $retakeTerm = Term::factory()->create(['state' => Term::StateClosed, 'starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->subMonth()->toDateString()]);
        $currentTerm = Term::factory()->create(['state' => Term::StateActive, 'starts_on' => now()->subWeek()->toDateString(), 'ends_on' => now()->addMonths(3)->toDateString()]);

        foreach ([$priorTerm, $retakeTerm, $currentTerm] as $term) {
            $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
            TermCalendarWindow::factory()->for($package, 'package')->create([
                'window_type' => TermCalendarWindow::TypeEnrollment,
                'opens_on' => now()->subDay()->toDateString(),
                'closes_on' => now()->addWeek()->toDateString(),
                'cutoff_at' => '23:59:59',
            ]);
        }

        $courseA = Course::factory()->create(['code' => 'IT-101']);
        $courseB = Course::factory()->create(['code' => 'IT-102']);
        $courseC = Course::factory()->create(['code' => 'IT-201']);
        $courseD = Course::factory()->create(['code' => 'IT-202']);
        $courseE = Course::factory()->create(['code' => 'IT-203']);

        $specA = CourseSpecification::factory()->for($courseA)->create(['credit_units' => '3.0', 'scheduling_treatment' => CourseSpecification::SchedulingRecurring]);
        $specB = CourseSpecification::factory()->for($courseB)->create(['credit_units' => '3.0', 'scheduling_treatment' => CourseSpecification::SchedulingRecurring]);
        $specC = CourseSpecification::factory()->for($courseC)->create(['credit_units' => '3.0', 'scheduling_treatment' => CourseSpecification::SchedulingRecurring]);
        $specD = CourseSpecification::factory()->for($courseD)->create(['credit_units' => '3.0', 'scheduling_treatment' => CourseSpecification::SchedulingRecurring]);
        $specE = CourseSpecification::factory()->for($courseE)->create(['credit_units' => '3.0', 'scheduling_treatment' => CourseSpecification::SchedulingRecurring]);

        // OR-group prerequisite on Course C: (Course A OR Course B)
        CourseRequirement::query()->create(['course_specification_id' => $specC->id, 'related_course_id' => $courseA->id, 'rule_type' => CourseRequirement::TypePrerequisite, 'group_key' => 'OR-AB', 'state' => CourseRequirement::StateActive, 'sequence' => 1]);
        CourseRequirement::query()->create(['course_specification_id' => $specC->id, 'related_course_id' => $courseB->id, 'rule_type' => CourseRequirement::TypePrerequisite, 'group_key' => 'OR-AB', 'state' => CourseRequirement::StateActive, 'sequence' => 2]);
        // Corequisite on Course D: Course E
        CourseRequirement::query()->create(['course_specification_id' => $specD->id, 'related_course_id' => $courseE->id, 'rule_type' => CourseRequirement::TypeCorequisite, 'group_key' => 'COREQ-E', 'state' => CourseRequirement::StateActive, 'sequence' => 1]);

        $entryA = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($specA)->create(['year_level' => 1, 'term_label' => 'First', 'term_type' => 'Regular']);
        $entryB = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($specB)->create(['year_level' => 1, 'term_label' => 'First', 'term_type' => 'Regular']);
        $entryC = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($specC)->create(['year_level' => 2, 'term_label' => 'First', 'term_type' => 'Regular']);
        $entryD = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($specD)->create(['year_level' => 2, 'term_label' => 'First', 'term_type' => 'Regular']);
        $entryE = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($specE)->create(['year_level' => 2, 'term_label' => 'First', 'term_type' => 'Regular']);

        $timetable = PublishedTimetableVersion::factory()->for($currentTerm)->create(['state' => PublishedTimetableVersion::StatePublished]);
        $sections = collect([$entryA, $entryB, $entryC, $entryD, $entryE])->mapWithKeys(function (CurriculumEntry $entry, int $idx) use ($currentTerm, $timetable, $courseA, $courseB, $courseC, $courseD, $courseE): array {
            $offering = TermOffering::factory()->for($currentTerm)->for($entry)->create(['state' => TermOffering::StateScheduled]);
            $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 30]);
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);
            $code = [$courseA->code, $courseB->code, $courseC->code, $courseD->code, $courseE->code][$idx];

            return [$code => $section];
        });

        // Student passed Course A (1.75) in priorTerm, then failed Course A (5.00) in retakeTerm.
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $profile = StudentProfile::factory()->for($studentUser, 'user')->for($program)->for($curriculum)->create();

        foreach ([[$priorTerm, '1.75'], [$retakeTerm, '5.00']] as [$pastTerm, $grade]) {
            $pastOffering = TermOffering::factory()->for($pastTerm)->for($entryA)->create(['state' => TermOffering::StateScheduled]);
            $pastSection = Section::factory()->for($pastOffering, 'termOffering')->create(['state' => Section::StateOpen]);
            $pastEnrollment = Enrollment::factory()->for($pastTerm)->for($studentUser, 'credentialUser')->for($profile)->create(['officially_enrolled_at' => now()->subMonths(4), 'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
            $courseEnrollment = CourseEnrollment::query()->create(['enrollment_id' => $pastEnrollment->id, 'term_offering_id' => $pastOffering->id, 'section_id' => $pastSection->id, 'units_snapshot' => 3, 'is_current' => true, 'status' => CourseEnrollment::StatusActive]);
            $roster = GradeRoster::factory()->for($pastOffering, 'termOffering')->for($pastSection)->for($faculty, 'faculty')->create(['state' => GradeRoster::StateReleased]);
            $row = GradeRosterRow::query()->create(['grade_roster_id' => $roster->id, 'course_enrollment_id' => $courseEnrollment->id, 'is_current_membership' => true, 'final_result' => $grade, 'current_outcome_code' => $grade, 'released_at' => now()]);
            GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $row->id, 'event_type' => GradeOutcomeEvent::TypeInitialRelease, 'result_code' => $grade, 'recorded_by' => $faculty->id, 'released_at' => now(), 'source_key' => 'test-'.$row->id]);
        }

        $currentCase = Enrollment::factory()->for($currentTerm)->for($studentUser, 'credentialUser')->for($profile)->create([
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $currentCase->id])->instance();

        $initialOptions = (fn (): array => $this->sectionOptions())->call($page);
        $this->assertStringContainsString('Already passed', $initialOptions[$sections['IT-101']->id]);
        $this->assertStringNotContainsString('Prereq unmet', $initialOptions[$sections['IT-201']->id]);
        $this->assertStringContainsString('Coreq unmet', $initialOptions[$sections['IT-202']->id]);

        $withCoreqSelected = (fn (): array => $this->sectionOptions([$sections['IT-203']->id]))->call($page);
        $this->assertStringNotContainsString('Coreq unmet', $withCoreqSelected[$sections['IT-202']->id]);

        // Transferee stale credit evaluation check via latestCurrentFor()
        $cycle = AdmissionCycle::factory()->create();
        $reqSet = AdmissionRequirementSet::factory()->for($cycle)->create();
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $application = AdmissionApplication::factory()->for($applicant, 'user')->for($cycle)->for($program)->create([
            'application_path' => AdmissionApplication::PathTransferee,
            'application_state' => AdmissionApplication::StateAdmitted,
        ]);
        $staleSubmission = ApplicationSubmissionVersion::factory()->for($application, 'application')->for($reqSet, 'requirementSet')->create(['version' => 1]);
        $currentSubmission = ApplicationSubmissionVersion::factory()->for($application, 'application')->for($reqSet, 'requirementSet')->create(['version' => 2]);
        $application->update(['current_submission_version_id' => $currentSubmission->id]);
        AdmissionDecision::factory()->for($application, 'application')->create(['decision' => AdmissionApplication::StateAdmitted]);

        $transfereeCase = Enrollment::factory()->for($currentTerm)->for($application, 'admissionApplication')->for($applicant, 'credentialUser')->create([
            'student_profile_id' => null,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        RegistrationCreditEvaluation::query()->create([
            'enrollment_id' => $transfereeCase->id,
            'application_submission_version_id' => $staleSubmission->id,
            'curriculum_version_id' => $curriculum->id,
            'credited_curriculum_entry_ids' => [$entryA->id],
            'authority_reference' => 'STALE-EVAL',
            'approved_on' => now()->toDateString(),
            'recorded_by' => $registrar->id,
            'recorded_at' => now()->subDay(),
        ]);

        $transfereePage = Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $transfereeCase->id])->instance();
        $staleOptions = (fn (): array => $this->sectionOptions())->call($transfereePage);
        $this->assertStringNotContainsString('Already passed', $staleOptions[$sections['IT-101']->id]);
        $this->assertStringContainsString('Prereq unmet', $staleOptions[$sections['IT-201']->id]);

        RegistrationCreditEvaluation::query()->create([
            'enrollment_id' => $transfereeCase->id,
            'application_submission_version_id' => $currentSubmission->id,
            'curriculum_version_id' => $curriculum->id,
            'credited_curriculum_entry_ids' => [$entryA->id],
            'authority_reference' => 'CURRENT-EVAL',
            'approved_on' => now()->toDateString(),
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);

        $freshTransfereePage = Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $transfereeCase->id])->instance();
        $currentOptions = (fn (): array => $this->sectionOptions())->call($freshTransfereePage);
        $this->assertStringContainsString('Already passed', $currentOptions[$sections['IT-101']->id]);
        $this->assertStringNotContainsString('Prereq unmet', $currentOptions[$sections['IT-201']->id]);
    }

    public function test_admission_applications_table_displays_top_filters_and_grade_roster_uses_semantic_state_colors_and_clear_export_labels(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $appsComponent = Livewire::actingAs($registrar)->test(ListAdmissionApplications::class);
        $appsComponent->assertSee('Admission cycle')->assertSee('Program')->assertSee('Student type');
        $appsComponent->instance()->bootedInteractsWithTable();
        $table = $appsComponent->instance()->getTable();
        $this->assertSame(FiltersLayout::Dropdown, $table->getFiltersLayout());
        $this->assertFalse($table->hasDeferredFilters());
        $this->assertSame(
            ['admission_cycle_id', 'program_id', 'application_path', 'application_state'],
            array_slice(array_keys($table->getFilters()), 0, 4),
        );

        $this->assertSame('success', GradeRostersTable::stateColor(GradeRoster::StateReleased));
        $this->assertSame('warning', GradeRostersTable::stateColor(GradeRoster::StateReturned));
        $this->assertSame('danger', GradeRostersTable::stateColor(GradeRoster::StateLateNotSubmitted));
        $this->assertSame('info', GradeRostersTable::stateColor(GradeRoster::StateSubmitted));
        $this->assertSame('gray', GradeRostersTable::stateColor(GradeRoster::StateDraft));

        Storage::fake('local');
        Mail::fake();
        [$case, $program, $term, , $section] = $this->advisedEnrollmentFixture(Enrollment::SelectionStandardCurriculum);
        $plan = app(CreateFeePlanDraft::class)->execute(
            $program,
            $term,
            [['code' => 'TUITION', 'label' => 'Tuition fee', 'amount' => '3000.00']],
            $accounting,
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment payment', 'purpose' => 'Enrollment', 'amount' => '3000.00', 'due_at' => now()->addDays(5)->format('Y-m-d H:i:s'), 'required_for_enrollment' => true]],
        );
        app(PublishFeePlan::class)->execute($plan, $accounting, 'SYN-PLAN-EXPORT', CarbonImmutable::parse('2026-08-15'));
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);
        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting, $proposal->id, $plan->fresh()->id);
        $obligation = $assessment->obligations()->firstOrFail();
        $this->enableDigitalChannels($accounting);
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $assessment->termAccount,
            $case->credentialUser,
            UploadedFile::fake()->image('receipt.png'),
            '3000.00',
            'bank_transfer',
            now()->subHour(),
            'REF-EXPORT-93',
            [$obligation->id],
        );
        app(ReviewPaymentEvidence::class)->verify($evidence, $accounting, '3000.00', 'OR-9301');

        $cor = CorVersion::factory()->for($case)->for($proposal, 'proposalVersion')->for($proposal->timetableVersion, 'timetableVersion')->for($assessment)->create(['version' => 1, 'snapshot' => ['courses' => []]]);
        $case->update([
            'current_cor_version_id' => $cor->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);

        Livewire::actingAs($accounting)
            ->withQueryParams(['view' => 'history'])
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Export verified payments (Excel CSV)');
    }

    /** @return array{Enrollment,Program,Term,CurriculumVersion,Section} */
    private function advisedEnrollmentFixture(string $selectionBasis, string $creditUnits = '3.0'): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addWeek()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);

        $program = Program::factory()->create(['is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $spec = CourseSpecification::factory()->create([
            'credit_units' => $creditUnits,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec)->create();
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 30]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $section->id]);

        $cycle = AdmissionCycle::factory()->for($term)->create();
        $reqSet = AdmissionRequirementSet::factory()->published()->for($cycle)->create([
            'application_path' => AdmissionApplication::PathFirstYear,
        ]);
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $application = AdmissionApplication::factory()->for($applicant, 'user')->for($cycle, 'admissionCycle')->for($program)->create([
            'term_id' => $term->id,
            'application_path' => AdmissionApplication::PathFirstYear,
            'application_state' => AdmissionApplication::StateAdmitted,
        ]);
        $submission = ApplicationSubmissionVersion::factory()->for($application, 'application')->for($reqSet, 'requirementSet')->create([
            'submitted_by' => $applicant->id,
        ]);
        $application->update(['current_submission_version_id' => $submission->id]);
        $decision = AdmissionDecision::factory()->admitted()->for($application, 'application')->create([
            'application_submission_version_id' => $submission->id,
        ]);
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application)->create([
            'result' => RegistrarEnrollmentClearance::ResultCleared,
            'external_checks_confirmed' => true,
            'recorded_by' => $decision->decided_by,
        ]);

        $case = Enrollment::factory()->for($term)->for($application, 'admissionApplication')->for($applicant, 'credentialUser')->create([
            'student_profile_id' => null,
            'selection_basis' => $selectionBasis,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);

        return [$case, $program, $term, $curriculum, $section];
    }

    private function enableDigitalChannels(User $accounting): void
    {
        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [
                    [
                        'bank_name' => 'Land Bank of the Philippines',
                        'branch' => 'Talisay',
                        'account_name' => 'TALA State University',
                        'account_number' => '0011-2233-44',
                    ],
                ],
            ],
            'e_wallets' => [
                'enabled' => false,
                'accounts' => [],
            ],
            'cash' => [
                'enabled' => true,
                'location' => 'Cashier Window 1',
            ],
        ], $accounting);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
