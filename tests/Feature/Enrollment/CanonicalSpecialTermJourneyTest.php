<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\IssueRegistrationProposal;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\SaveFinalGradeResult;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\PaymentEvidenceVersion;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalSpecialTermJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleFaculty, User::StaffRoleAccounting, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function canonical_special_term_journey_is_exact_authorized_and_isolated(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $term = Term::factory()->create([
            'label' => 'Special Term 2026',
            'type' => Term::TypeSpecialTerm,
            'state' => Term::StateActive,
            'default_max_units' => 99,
        ]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessEnrollment,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => 'grade_entry',
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'program_id' => $curriculum->program_id,
            'curriculum_version_id' => $curriculum->id,
            'academic_standing' => StudentProfile::StandingGraduationCandidate,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'credential_user_id' => $student->id,
            'term_id' => $term->id,
            'case_reference' => 'REG-2026-ST-001',
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'lock_version' => 1,
        ]);
        $plannedEntry = $this->entry($curriculum, 'ITE3-ST', 'Third Year', 'Special Term', Term::TypeSpecialTerm, 3);
        $retakeEntry = $this->entry($curriculum, 'IT201-ST-R', 'Second Year', 'First Semester', Term::TypeFirstSemester, 3);
        $this->recordPriorCanonicalHistory($profile, $curriculum, $retakeEntry, $registrar);
        $dependentEntry = $this->entry($curriculum, 'IT301-ST-BLOCKED', 'Third Year', 'First Semester', Term::TypeFirstSemester, 3);
        CourseRequirement::factory()->create([
            'course_specification_id' => $dependentEntry->course_specification_id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'related_course_id' => $retakeEntry->courseSpecification->course_id,
            'minimum_grade' => '3.00',
        ]);
        $plannedSection = $this->publishedSection($term, $plannedEntry, $faculty, 'CLS-ITE3-ST-A', TermOffering::CategoryRegular);
        $retakeSection = $this->publishedSection($term, $retakeEntry, $faculty, 'CLS-IT201-ST-R', TermOffering::CategorySpecial);
        $dependentSection = $this->publishedSection($term, $dependentEntry, $faculty, 'CLS-IT301-ST-BLOCKED', TermOffering::CategorySpecial);
        $otherTerm = Term::factory()->create(['label' => 'First Semester 2026', 'state' => Term::StateActive]);
        $otherEnrollment = Enrollment::factory()->create(['term_id' => $otherTerm->id]);
        $otherEnrollmentState = $otherEnrollment->only(['canonical_outcome', 'status', 'lock_version', 'current_proposal_version_id']);

        try {
            app(PrepareRegistrationProposal::class)->execute(
                $enrollment,
                $registrar,
                [$plannedSection->id, $retakeSection->id, $dependentSection->id],
                1,
            );
            $this->fail('A failed IT201 attempt must exclude dependent IT301 only.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('prerequisite', $exception->getMessage());
        }

        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $enrollment,
            $registrar,
            [$plannedSection->id, $retakeSection->id],
            1,
        );

        $this->assertSame('3.00', data_get($proposal->source_snapshot, 'unit_load.normal_total'));
        $this->assertSame('6.00', data_get($proposal->source_snapshot, 'unit_load.requested_total'));
        $this->assertTrue(data_get($proposal->source_snapshot, 'unit_load.requires_graduating_overload'));
        $this->assertSame(
            [$plannedSection->term_offering_id, $retakeSection->term_offering_id],
            $proposal->items->pluck('term_offering_id')->all(),
        );

        try {
            app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
            $this->fail('The over-ceiling proposal must remain Draft without exact external authority.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('graduating-overload authority', $exception->getMessage());
        }

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertActionVisible('recordGraduatingOverloadAuthority')
            ->mountAction('recordGraduatingOverloadAuthority')
            ->assertMountedActionModalSee('Normal total: 3.00 units');
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->callAction('recordGraduatingOverloadAuthority', data: [
                'authority_reference' => 'EXT-GRAD-2026-001',
                'authority_date' => '2026-08-25',
                'evidence_reference' => 'EVID-GRAD-2026-001',
                'reason' => 'Externally approved graduating overload for this exact Special Term proposal.',
            ])
            ->assertNotified('Graduating overload authority recorded');

        $issued = app(IssueRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        $this->assertSame('Issued', $issued->state);
        $this->assertSame('REG-2026-ST-001', $issued->enrollment->case_reference);

        $confirmed = app(ConfirmRegistrationProposal::class)->execute($issued, $student);
        $profile->update(['academic_standing' => StudentProfile::StandingRegular]);
        $this->assertContains(
            'Graduating overload authority',
            app(RegistrationReadinessQuery::class)->for($enrollment->fresh())['blockers'],
        );

        try {
            app(PlaceRegistrationProposal::class)->execute($confirmed, $registrar);
            $this->fail('Placement must revalidate the current proposal-specific overload authority.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('graduating-overload authority', $exception->getMessage());
        }

        $profile->update(['academic_standing' => StudentProfile::StandingGraduationCandidate]);
        $placed = app(PlaceRegistrationProposal::class)->execute($confirmed->fresh(), $registrar);

        $this->assertCount(2, $placed->items->pluck('reservation')->filter());

        $assessment = app(RecordAuthorizedIndividualAssessment::class)->execute(
            $enrollment->fresh(),
            $accounting,
            Assessment::CategorySpecialTerm,
            'ACT-2026-ST-001',
            CarbonImmutable::parse('2026-08-25', config('app.timezone')),
            [
                ['code' => 'SPECIAL-TERM', 'label' => 'Authorized Special Term assessment', 'amount' => '6000.00'],
            ],
            [
                [
                    'code' => 'ENROLLMENT',
                    'label' => 'Enrollment obligation',
                    'purpose' => 'Enrollment',
                    'amount' => '3000.00',
                    'due_at' => now()->subMinute()->toDateTimeString(),
                    'required_for_enrollment' => true,
                ],
                [
                    'code' => 'REMAINING',
                    'label' => 'Remaining Special Term obligation',
                    'purpose' => 'TermPayment',
                    'amount' => '3000.00',
                    'due_at' => now()->addMonth()->toDateTimeString(),
                    'required_for_enrollment' => false,
                ],
            ],
        );

        $this->assertSame('6000.00', $assessment->total);
        $this->assertSame('3000.00', $assessment->obligations->first()->amount);

        app(RecordApprovedCoverage::class)->execute(
            $assessment->termAccount,
            $assessment->obligations->first(),
            [
                'category' => ApprovedCoverage::CategoryGovernmentSubsidy,
                'safe_source_description' => 'Coordinated synthetic Special Term subsidy result',
                'authority_reference' => 'COV-2026-ST-001',
                'authority_date' => '2026-08-25',
                'effective_date' => now()->toDateString(),
                'amount' => '2000.00',
            ],
            $accounting,
        );
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $assessment->term_account_id,
            'claimed_amount' => '1000.00',
            'channel' => 'gcash_manual',
            'paid_at' => now(),
            'payment_reference' => 'PAY-2026-ST-001',
            'submitted_by' => $student->id,
            'submitted_at' => now(),
        ]);
        app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $accounting,
            '1000.00',
            'PAY-2026-ST-001',
        );

        $clearance = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($enrollment->fresh());
        $this->assertSame('Cleared', $clearance['state']);
        $this->assertSame('Mixed', $clearance['satisfaction_basis']);
        $this->assertSame('2000.00', $clearance['coverage_applied']);
        $this->assertSame('1000.00', $clearance['payment_applied']);

        $official = app(FinalizeOfficialEnrollment::class)->execute($enrollment->fresh(), $registrar);
        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame('6000.00', data_get($official->currentCorVersion->snapshot, 'assessment_total'));
        $this->assertSame($term->id, data_get($official->currentCorVersion->snapshot, 'term_id'));
        $this->assertCount(2, $official->courseEnrollments);

        app(ManageTeachingAssignment::class)->designate($plannedSection, $faculty, $registrar, 'ASSIGN-ITE3-ST');
        app(ManageTeachingAssignment::class)->designate($retakeSection, $faculty, $registrar, 'ASSIGN-IT201-ST');
        $plannedRoster = app(SynchronizeOfficialGradeRoster::class)->execute($plannedSection, $registrar);
        $retakeRoster = app(SynchronizeOfficialGradeRoster::class)->execute($retakeSection, $registrar);
        $this->assertCount(1, $plannedRoster->rows);
        $this->assertCount(1, $retakeRoster->rows);

        $this->releaseRoster($plannedRoster, '1.75', $faculty, $registrar, 'RELEASE-ITE3-ST');
        $partial = app(TermWeightedAverageProjection::class)->forStudentAndTerm($profile, $term);
        $this->assertSame(AcademicAverageReadiness::GradesNotComplete, $partial['state']);
        $this->assertNull($partial['value']);

        $this->releaseRoster($retakeRoster, '2.50', $faculty, $registrar, 'RELEASE-IT201-ST');
        $termAverage = app(TermWeightedAverageProjection::class)->forStudentAndTerm($profile, $term);
        $cumulative = app(CumulativeGwaProjection::class)->forStudent($profile);
        $evaluation = app(CurriculumEvaluation::class)->forStudent($profile);
        $retakeEvaluation = collect($evaluation['required'])->first(
            fn (array $row): bool => (int) $row['curriculum_entry']->id === (int) $retakeEntry->id,
        );

        $this->assertSame(AcademicAverageReadiness::Available, $termAverage['state']);
        $this->assertSame('2.13', $termAverage['value']);
        $this->assertSame('2.01', $cumulative['value']);
        $this->assertSame(32, $cumulative['included_attempts']);
        $this->assertSame('Completed', $retakeEvaluation['status']);
        $this->assertSame(2, $retakeEvaluation['attempt_count']);
        $this->assertTrue(GradeOutcomeEvent::query()->where('result_code', '5.00')->whereHas(
            'row.courseEnrollment.enrollment',
            fn ($query) => $query->where('student_profile_id', $profile->id),
        )->exists());

        $this->assertSame($otherEnrollmentState, $otherEnrollment->fresh()->only(array_keys($otherEnrollmentState)));
        $this->assertFalse(CourseEnrollment::query()->where('enrollment_id', $otherEnrollment->id)->exists());
    }

    private function recordPriorCanonicalHistory(
        StudentProfile $profile,
        CurriculumVersion $curriculum,
        CurriculumEntry $failedEntry,
        User $registrar,
    ): void {
        $priorTerm = Term::factory()->create([
            'label' => 'Second Semester 2025–2026',
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-05-31',
            'state' => Term::StateClosed,
        ]);
        $priorEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'credential_user_id' => $profile->user_id,
            'term_id' => $priorTerm->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => '2026-01-05 08:00:00',
        ]);

        $this->recordHistoricalResult($priorEnrollment, $priorTerm, $failedEntry, '5.00', $registrar);

        foreach ([...array_fill(0, 25, '2.00'), ...array_fill(0, 4, '1.25')] as $index => $result) {
            $entry = $this->entry(
                $curriculum,
                'HIST-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'Prior',
                'Second Semester',
                Term::TypeSecondSemester,
                3,
            );
            $this->recordHistoricalResult($priorEnrollment, $priorTerm, $entry, $result, $registrar);
        }
    }

    private function recordHistoricalResult(
        Enrollment $enrollment,
        Term $term,
        CurriculumEntry $entry,
        string $result,
        User $registrar,
    ): void {
        $offering = TermOffering::factory()->for($term)->for($entry)->create([
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::query()->create([
            'term_offering_id' => $offering->id,
            'code' => 'HIST-SECTION-'.$offering->id,
            'source' => Section::SourceRegular,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $courseEnrollment = CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'units_snapshot' => '3.00',
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $registrar->id,
            'state' => GradeRoster::StateReleased,
            'released_by' => $registrar->id,
            'released_at' => '2026-06-01 08:00:00',
        ]);
        $row = GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'final_result' => $result,
            'current_outcome_code' => $result,
            'current_outcome_category' => (float) $result <= 4 ? GradeRosterRow::CategoryPassing : GradeRosterRow::CategoryFailed,
            'released_at' => '2026-06-01 08:00:00',
        ]);
        GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id,
            'result_code' => $result,
            'new_value' => $result,
            'new_category' => $row->current_outcome_category,
            'recorded_by' => $registrar->id,
            'released_at' => '2026-06-01 08:00:00',
        ]);
    }

    private function releaseRoster(
        GradeRoster $roster,
        string $result,
        User $faculty,
        User $registrar,
        string $authority,
    ): void {
        app(SaveFinalGradeResult::class)->execute($roster->rows->sole(), $result, null, $faculty);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $faculty);
        app(PostAndReleaseGradeRoster::class)->execute($submitted, $registrar, $authority);
    }

    private function entry(
        CurriculumVersion $curriculum,
        string $code,
        string $yearLevel,
        string $termLabel,
        string $termType,
        float $units,
    ): CurriculumEntry {
        return CurriculumEntry::factory()->for($curriculum)->create([
            'course_specification_id' => CourseSpecification::factory()->create([
                'title' => $code,
                'credit_units' => $units,
            ])->id,
            'year_level' => $yearLevel,
            'term_label' => $termLabel,
            'term_type' => $termType,
        ]);
    }

    private function publishedSection(
        Term $term,
        CurriculumEntry $entry,
        User $faculty,
        string $code,
        string $category,
    ): Section {
        $offering = TermOffering::factory()->for($term)->for($entry)->create([
            'category' => $category,
            'special_reason' => $category === TermOffering::CategorySpecial ? 'Authorized retake' : null,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => $code,
            'state' => Section::StateOpen,
        ]);
        $timetable = PublishedTimetableVersion::query()
            ->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->first() ?? PublishedTimetableVersion::factory()->for($term)->create([
                'state' => PublishedTimetableVersion::StatePublished,
            ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'day_of_week' => $category === TermOffering::CategoryRegular ? 1 : 2,
        ]);

        return $section;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
