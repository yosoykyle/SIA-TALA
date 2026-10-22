<?php

namespace Tests\Feature;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\RecordAcademicDecision;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationSelectionBasisResolver;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ViewStudentLifecycleChange;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Student\Pages\LifecycleView;
use App\Models\AcademicDecision;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D6BAcademicStandingLifecycleResultTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function system_admin_cannot_record_individual_standing_or_lifecycle_results(): void
    {
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $profile = StudentProfile::factory()->create();
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateRecordedApproved,
        ]);

        $this->actingAs($systemAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(StudentLifecycleChangeResource::canCreate());
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->assertForbidden();
        Livewire::test(ListStudentLifecycleChanges::class)
            ->assertActionHidden(TestAction::make('apply')->table($change))
            ->assertActionHidden(TestAction::make('cancel')->table($change));

        try {
            app(RecordAcademicDecision::class)->execute(
                $profile, null, AcademicDecision::EffectBlocked, 'CRAFTED-AUTHORITY', today(),
                'Crafted request.', today(), null, $systemAdmin,
            );
            $this->fail('System Super Admin unexpectedly recorded an academic decision.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('academic_decisions', 0);
        }

        try {
            app(StudentLifecycleService::class)->record([], $systemAdmin);
            $this->fail('System Super Admin unexpectedly recorded a lifecycle result.');
        } catch (AuthorizationException) {
            $this->assertSame(1, StudentLifecycleChange::query()->whereKey($change->id)->count());
        }
    }

    #[Test]
    public function registrar_records_an_attributable_academic_effect_without_mutating_legacy_standing(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingProbationary,
        ]);
        $this->curriculumEntry($profile);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(GradesAndCompletion::class)->assertActionVisible('recordAcademicDecision');
        app(RecordAcademicDecision::class)->execute(
            $profile, null, AcademicDecision::EffectAdvisingRequired, 'ACADEMIC-BOARD-D6B', today(),
            'Released results require an individually advised proposal.', today(), null, $registrar,
        );

        $this->assertSame(StudentProfile::StandingProbationary, $profile->fresh()->academic_standing);
        $this->assertSame(AcademicDecision::EffectAdvisingRequired, app(AcademicEnrollmentEffect::class)->forStudent($profile)['effect']);
        $this->assertDatabaseHas('academic_decisions', [
            'student_profile_id' => $profile->id,
            'effect' => AcademicDecision::EffectAdvisingRequired,
            'authority_reference' => 'ACADEMIC-BOARD-D6B',
            'recorded_by' => $registrar->id,
        ]);
    }

    #[Test]
    public function staff_lifecycle_surfaces_identify_the_student_and_complete_recorded_result(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['name' => 'Diploma in Test Operations']);
        $profile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'student_number' => 'TAL-D6B-001',
            'first_name' => 'Maya',
            'middle_name' => null,
            'last_name' => 'Santos',
        ]);
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'recorded_by' => $registrar->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
            'authority' => 'Registrar-approved withdrawal',
            'reason' => 'Approved withdrawal from the current Term.',
            'private_source_reference' => 'PRIVATE-D6B-REF',
            'decided_on' => '2026-07-28',
            'effective_on' => '2026-07-30',
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListStudentLifecycleChanges::class)
            ->assertSee('Santos, Maya')
            ->assertSee('TAL-D6B-001')
            ->assertSee('Diploma in Test Operations')
            ->assertSee('Applied to official record')
            ->assertSee('Responsible office: Registrar Office');

        Livewire::test(ViewStudentLifecycleChange::class, ['record' => $change->getRouteKey()])
            ->assertSee('Recorded Result')
            ->assertSee('Decision Date')
            ->assertSee('Recorded By')
            ->assertSee($registrar->name)
            ->assertSee('Registrar Office')
            ->assertSee('PRIVATE-D6B-REF');
    }

    #[Test]
    public function student_academic_status_is_owner_scoped_and_hides_staff_only_decision_evidence(): void
    {
        $student = $this->staff('student');
        $ownProfile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        $otherProfile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['label' => 'Second Semester']);
        $ownChange = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $ownProfile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
            'authority' => 'STAFF-ONLY AUTHORITY',
            'reason' => 'STAFF-ONLY REASON',
            'private_source_reference' => 'STAFF-ONLY REFERENCE',
        ]);
        $otherChange = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $otherProfile->id,
            'term_id' => $term->id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(LifecycleView::class)
            ->assertCanSeeTableRecords([$ownChange])
            ->assertCanNotSeeTableRecords([$otherChange])
            ->assertSee('Enrollment guidance: ALLOWED')
            ->assertSee('Applied to your official record')
            ->assertSee('Registrar Office')
            ->assertDontSee('STAFF-ONLY AUTHORITY')
            ->assertDontSee('STAFF-ONLY REASON')
            ->assertDontSee('STAFF-ONLY REFERENCE');
    }

    #[Test]
    public function current_official_retake_and_released_correction_override_stale_roster_summary(): void
    {
        $this->freezeTime();
        [$profile, $case, $offering, $requirement] = $this->eligibilityFixture();
        $source = CourseSpecification::factory()->create(['course_id' => $requirement->related_course_id]);
        $this->officialAttempt($profile, $source, '5.00', today()->subYear()->toDateString());
        $row = $this->officialAttempt($profile, $source, '2.00', today()->subMonths(6)->toDateString());
        $row->update(['current_outcome_code' => '5.00', 'current_outcome_category' => 'Failed']);
        $query = app(RegistrationAcademicEligibilityQuery::class);

        $this->assertTrue($query->passes($case, $profile->curriculumVersion, collect([$offering])));
        $this->assertSame(AcademicDecision::EffectAllowed, app(AcademicEnrollmentEffect::class)->forStudent($profile)['effect']);

        GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id, 'event_type' => GradeOutcomeEvent::TypePostedCorrection,
            'result_code' => '5.00', 'released_at' => null,
        ]);
        $this->assertTrue($query->passes($case, $profile->curriculumVersion, collect([$offering])));

        GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id, 'event_type' => GradeOutcomeEvent::TypePostedCorrection,
            'result_code' => '5.00', 'released_at' => now(),
        ]);
        $this->assertFalse($query->passes($case, $profile->curriculumVersion, collect([$offering])));
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, app(RegistrationSelectionBasisResolver::class)->forContinuingStudent($profile, $case->term));
    }

    #[Test]
    public function unresolved_course_results_require_advising_and_block_only_dependent_subjects(): void
    {
        $this->freezeTime();
        [$profile, $case, $dependent, $requirement] = $this->eligibilityFixture();
        $source = CourseSpecification::factory()->create(['course_id' => $requirement->related_course_id]);
        $row = $this->officialAttempt($profile, $source, null, today()->subMonths(6)->toDateString());
        $unrelated = TermOffering::factory()->create([
            'term_id' => $case->term_id,
            'curriculum_entry_id' => CurriculumEntry::factory()->create(['curriculum_version_id' => $profile->curriculum_version_id])->id,
        ]);
        $query = app(RegistrationAcademicEligibilityQuery::class);

        $this->assertSame(AcademicDecision::EffectAdvisingRequired, app(AcademicEnrollmentEffect::class)->forStudent($profile)['effect']);
        $this->assertFalse($query->passes($case, $profile->curriculumVersion, collect([$dependent])));
        $this->assertTrue($query->passes($case, $profile->curriculumVersion, collect([$unrelated])));

        GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $row->id, 'result_code' => 'INC', 'released_at' => now()]);
        $this->assertFalse($query->passes($case, $profile->curriculumVersion, collect([$dependent])));
        $this->assertTrue($query->passes($case, $profile->curriculumVersion, collect([$unrelated])));
    }

    #[Test]
    public function mapped_approved_credit_satisfies_the_target_course_and_honors_minimum_grade(): void
    {
        [$profile, $case, $offering, $requirement] = $this->eligibilityFixture();
        $source = CourseSpecification::factory()->create(['course_id' => $requirement->related_course_id]);
        $targetEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id, 'course_specification_id' => $source->id,
        ]);
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id, 'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        $credit = ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $change->id, 'curriculum_entry_id' => $targetEntry->id,
            'source_course_id' => Course::factory()->create()->id, 'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'numeric_grade' => '2.00',
        ]);
        $query = app(RegistrationAcademicEligibilityQuery::class);

        $this->assertTrue($query->passes($case, $profile->curriculumVersion, collect([$offering])));

        $credit->update(['numeric_grade' => '3.50']);
        $this->assertFalse($query->passes($case, $profile->curriculumVersion, collect([$offering])));

        $credit->update(['numeric_grade' => null]);
        $this->assertTrue($query->passes($case, $profile->curriculumVersion, collect([$offering])));
        $requirement->update(['accepts_transfer_credit' => false]);
        $this->assertFalse($query->passes($case, $profile->curriculumVersion, collect([$offering])));
    }

    #[Test]
    public function concurrent_selected_subject_satisfies_a_corequisite_but_never_a_prerequisite(): void
    {
        [$profile, $case, $offering, $requirement] = $this->eligibilityFixture();
        $corequisite = TermOffering::factory()->create([
            'term_id' => $case->term_id,
            'curriculum_entry_id' => CurriculumEntry::factory()->create([
                'curriculum_version_id' => $profile->curriculum_version_id,
                'course_specification_id' => CourseSpecification::factory()->create(['course_id' => $requirement->related_course_id])->id,
            ])->id,
        ]);
        $selection = collect([$offering, $corequisite]);
        $query = app(RegistrationAcademicEligibilityQuery::class);

        $this->assertFalse($query->passes($case, $profile->curriculumVersion, $selection));

        $requirement->update(['rule_type' => CourseRequirement::TypeCorequisite]);
        $this->assertTrue($query->passes($case, $profile->curriculumVersion, $selection));
    }

    #[Test]
    public function selection_ignores_legacy_standing_and_preserves_exact_term_authoritative_effects(): void
    {
        $this->freezeTime();
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingIrregular]);
        $term = Term::factory()->create();
        $otherTerm = Term::factory()->create();
        $resolver = app(RegistrationSelectionBasisResolver::class);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->assertSame(Enrollment::SelectionStandardCurriculum, $resolver->forContinuingStudent($profile, $term));

        app(RecordAcademicDecision::class)->execute(
            $profile, $otherTerm, AcademicDecision::EffectBlocked, 'SCOPED-ACADEMIC-01', today(),
            'A recorded decision blocks only the named Term.', today(), null, $registrar,
        );
        $this->assertSame(Enrollment::SelectionStandardCurriculum, $resolver->forContinuingStudent($profile, $term));
        $this->assertSame(Enrollment::SelectionStandardCurriculum, $resolver->forContinuingStudent($profile));

        try {
            $resolver->forContinuingStudent($profile, $otherTerm);
            $this->fail('A genuine named academic block must guard entry.');
        } catch (ValidationException $exception) {
            $this->assertSame(['A recorded decision blocks only the named Term.'], $exception->errors()['academic_effect']);
        }

        app(RecordAcademicDecision::class)->execute(
            $profile, $term, AcademicDecision::EffectPendingDecision, 'SCOPED-ACADEMIC-02', today(),
            'A named institutional review remains open.', today(), null, $registrar,
        );
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A named institutional review remains open.');
        $resolver->forContinuingStudent($profile, $term);
    }

    #[Test]
    public function incompatible_lifecycle_remains_blocked_despite_an_allowed_academic_decision(): void
    {
        $this->freezeTime();
        $profile = StudentProfile::factory()->create(['lifecycle_status' => StudentProfile::LifecycleWithdrawn]);
        app(RecordAcademicDecision::class)->execute(
            $profile, null, AcademicDecision::EffectAllowed, 'ACADEMIC-ALLOWED-01', today(),
            'Academic review alone is allowed.', today(), null, $this->staff(User::StaffRoleRegistrar),
        );

        $effect = app(AcademicEnrollmentEffect::class)->forStudent($profile);

        $this->assertSame(AcademicDecision::EffectBlocked, $effect['effect']);
        $this->assertSame('Current student lifecycle', $effect['source']);
    }

    /** @return array{StudentProfile, Enrollment, TermOffering, CourseRequirement} */
    private function eligibilityFixture(): array
    {
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingRegular]);
        $profile->curriculumVersion->update(['state' => CurriculumVersion::StateActive, 'program_id' => $profile->program_id]);
        $case = Enrollment::factory()->create(['student_profile_id' => $profile->id]);
        $specification = CourseSpecification::factory()->create();
        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id, 'course_specification_id' => $specification->id,
        ]);
        $offering = TermOffering::factory()->create(['term_id' => $case->term_id, 'curriculum_entry_id' => $entry->id]);
        $requirement = CourseRequirement::factory()->create(['course_specification_id' => $specification->id, 'minimum_grade' => '3.0000']);

        return [$profile->fresh(), $case, $offering, $requirement];
    }

    private function officialAttempt(StudentProfile $profile, CourseSpecification $specification, ?string $result, string $startsOn): GradeRosterRow
    {
        $term = Term::factory()->create(['starts_on' => $startsOn, 'ends_on' => today()->subMonth()->toDateString()]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id, 'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled, 'officially_enrolled_at' => now(),
        ]);
        $entry = CurriculumEntry::query()->where('curriculum_version_id', $profile->curriculum_version_id)
            ->where('course_specification_id', $specification->id)->first()
            ?? CurriculumEntry::factory()->create([
                'curriculum_version_id' => $profile->curriculum_version_id, 'course_specification_id' => $specification->id,
            ]);
        $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id]);
        $course = CourseEnrollment::factory()->create(['enrollment_id' => $enrollment->id, 'term_offering_id' => $offering->id]);
        $row = GradeRosterRow::factory()->create(['course_enrollment_id' => $course->id]);
        if ($result !== null) {
            GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $row->id, 'result_code' => $result, 'released_at' => now()]);
        }

        return $row;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function curriculumEntry(StudentProfile $profile): CurriculumEntry
    {
        $course = Course::factory()->create(['code' => 'D6B-COURSE']);
        $specification = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'title' => 'D6B Course',
        ]);

        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $specification->id,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);
    }
}
