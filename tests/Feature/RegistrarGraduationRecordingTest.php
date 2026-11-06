<?php

namespace Tests\Feature;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\CorrectDegreeConferral;
use App\Actions\Completion\RecordDegreeConferral;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Student\Pages\Academics;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationApplication;
use App\Models\OperationalEvent;
use App\Models\ProgramShiftCreditEntry;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrarGraduationRecordingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        $this->travelTo(Carbon::parse('2028-06-30 10:00:00', 'Asia/Manila'));
    }

    #[Test]
    public function contextual_graduation_records_and_corrects_without_inventing_paper_metadata(): void
    {
        $fixture = $this->fixture();
        $this->approveCredit($fixture);
        $page = Livewire::actingAs($fixture['registrar'])->test(CompletionAndTor::class, [
            'completionView' => 'graduation', 'graduationStudentId' => $fixture['student']->id,
        ])->callAction('recordGraduation', data: ['degree_name' => 'Synthetic diploma'])
            ->assertHasNoActionErrors()->assertNotified('Graduation recorded');
        $first = $fixture['student']->degreeConferrals()->sole();
        $this->assertNull($first->conferred_on);
        $page->callAction('correctGraduation', data: ['degree_name' => 'Corrected synthetic diploma', 'reason' => 'The school corrected its printed degree name.'])
            ->assertHasNoActionErrors()->assertNotified('Graduation correction recorded');
        $corrected = $fixture['student']->degreeConferrals()->whereNotNull('active_scope_key')->sole();
        $this->assertSame($first->id, $corrected->supersedes_conferral_id);
        $this->assertNull($corrected->conferred_on);
        $this->assertNull($corrected->authority_reference);
        $this->assertSame(2, $fixture['student']->degreeConferrals()->count());
        $this->expectException(ValidationException::class);
        app(CorrectDegreeConferral::class)->execute($first, $fixture['registrar'], 'Conflicting change', null, null, 'A competing correction.');
    }

    #[Test]
    public function student_academics_renders_graduation_without_inventing_an_external_date(): void
    {
        $fixture = $this->fixture();
        $this->approveCredit($fixture);
        $studentUser = $fixture['student']->user;
        Role::findOrCreate('student', 'web');
        $studentUser->assignRole('student');
        app(RecordDegreeConferral::class)->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations');

        Livewire::actingAs($studentUser)->test(Academics::class, ['academicView' => 'graduation'])
            ->assertSuccessful()->assertSee('Diploma in Test Operations')
            ->assertSee('External graduation date not recorded');
    }

    #[Test]
    public function graduation_cannot_be_recorded_when_the_assigned_curriculum_has_no_requirements(): void
    {
        $student = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $projection = app(CompletionReadinessProjection::class)->forStudent($student);

        $this->assertSame(CompletionReadinessProjection::NotEligible, $projection['state']);
        $this->assertContains('curriculum:requirements-missing', array_column($projection['blockers'], 'code'));

        try {
            app(RecordDegreeConferral::class)->execute($student, $registrar, 'An unverified degree');
            $this->fail('An empty curriculum cannot establish completed graduation requirements.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conferral', $exception->errors());
            $this->assertDatabaseMissing('degree_conferrals', ['student_profile_id' => $student->id]);
            $this->assertSame(StudentProfile::LifecycleActive, $student->fresh()->lifecycle_status);
        }
    }

    #[Test]
    public function registrar_records_graduation_without_an_application_or_invented_external_metadata(): void
    {
        $fixture = $this->fixture();
        $this->approveCredit($fixture);
        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);

        $this->assertSame(CompletionReadinessProjection::ReadyForConferral, $projection['state']);
        $this->assertNull($projection['application']);
        $conferral = app(RecordDegreeConferral::class)->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations');

        $this->assertNull($conferral->graduation_application_id);
        $this->assertNull($conferral->conferred_on);
        $this->assertNull($conferral->authority_reference);
        $this->assertSame($fixture['registrar']->id, $conferral->recorded_by);
        $this->assertSame(now()->toDateTimeString(), $conferral->recorded_at->toDateTimeString());
        $this->assertSame(StudentProfile::LifecycleCompleted, $fixture['student']->fresh()->lifecycle_status);
        $this->assertSame('Approved credit', $conferral->final_evaluation_snapshot['required'][0]['status']);
        $this->assertDatabaseHas('student_lifecycle_changes', [
            'student_profile_id' => $fixture['student']->id,
            'type' => StudentLifecycleChange::TypeCompletion,
            'term_id' => null,
            'effective_on' => '2028-06-30',
            'recorded_by' => $fixture['registrar']->id,
            'private_source_reference' => 'degree-conferral:'.$conferral->id,
        ]);
        $this->assertDatabaseHas('operational_events', [
            'related_record_type' => DegreeConferral::class,
            'related_record_id' => $conferral->id,
            'event_type' => OperationalEvent::TypeConferralRecordedEmail,
        ]);
        $this->assertSame(CompletionReadinessProjection::Conferred, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);
        $snapshot = $conferral->fresh()->final_evaluation_snapshot;

        CurriculumEntry::factory()->create(['curriculum_version_id' => $fixture['student']->curriculum_version_id]);
        $this->travelTo(Carbon::parse('2028-07-01 10:00:00', 'Asia/Manila'));
        $retry = app(RecordDegreeConferral::class)->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations', '', '   ');

        $this->assertSame($conferral->id, $retry->id);
        $this->assertSame($snapshot, $conferral->fresh()->final_evaluation_snapshot);
        $this->assertDatabaseCount('degree_conferrals', 1);
        $this->assertDatabaseCount('graduation_applications', 0);
        $this->assertSame(1, StudentLifecycleChange::query()->where('type', StudentLifecycleChange::TypeCompletion)->count());
    }

    #[Test]
    public function provided_external_details_are_preserved_and_conflicting_retries_are_rejected(): void
    {
        $fixture = $this->fixture();
        $this->approveCredit($fixture);
        $application = GraduationApplication::factory()->create([
            'student_profile_id' => $fixture['student']->id,
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'term_id' => null,
        ]);

        $action = app(RecordDegreeConferral::class);
        $conferral = $action->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations', '2028-06-15', ' SIA-GRAD-2028 ', 'With distinction', 'HONOR-2028');

        $this->assertSame($application->id, $conferral->graduation_application_id);
        $this->assertSame('2028-06-15', $conferral->conferred_on->toDateString());
        $this->assertSame('SIA-GRAD-2028', $conferral->authority_reference);
        $this->assertSame('With distinction', $conferral->honor_text);
        $this->assertSame('HONOR-2028', $conferral->honor_authority_reference);
        $this->assertSame($conferral->id, $action->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations', '2028-06-15', 'SIA-GRAD-2028', 'With distinction', 'HONOR-2028')->id);
        $this->assertDatabaseHas('student_lifecycle_changes', ['private_source_reference' => 'degree-conferral:'.$conferral->id, 'effective_on' => '2028-06-15']);

        try {
            $action->execute($fixture['student'], $fixture['registrar'], 'A different degree');
            $this->fail('A conflicting retry must use a separate correction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conferral', $exception->errors());
        }

        $this->assertDatabaseCount('degree_conferrals', 1);
        $this->assertSame('Diploma in Test Operations', $conferral->fresh()->degree_name);
    }

    #[Test]
    public function an_old_curriculum_application_remains_history_without_blocking_current_graduation(): void
    {
        $fixture = $this->fixture();
        $this->approveCredit($fixture);
        $oldCurriculum = CurriculumVersion::factory()->create(['program_id' => $fixture['student']->program_id]);
        $application = GraduationApplication::factory()->create(['student_profile_id' => $fixture['student']->id, 'curriculum_version_id' => $oldCurriculum->id]);
        $before = $application->fresh()->getAttributes();
        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);

        $this->assertSame($application->id, $projection['application']->id);
        $this->assertSame([], $projection['blockers']);
        $conferral = app(RecordDegreeConferral::class)->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations');

        $this->assertNull($conferral->graduation_application_id);
        $this->assertSame($fixture['student']->curriculum_version_id, $conferral->curriculum_version_id);
        $this->assertSame($before, $application->fresh()->getAttributes());
    }

    #[Test]
    public function unreleased_results_still_prevent_graduation_without_an_application(): void
    {
        $fixture = $this->fixture();
        $this->courseEnrollment($fixture);

        $this->assertSame(CompletionReadinessProjection::AwaitingResultsOrClearance, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);
        try {
            app(RecordDegreeConferral::class)->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations');
            $this->fail('Unreleased required results must block graduation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conferral', $exception->errors());
        }

        $this->assertDatabaseCount('degree_conferrals', 0);
        $this->assertSame(StudentProfile::LifecycleActive, $fixture['student']->fresh()->lifecycle_status);
    }

    #[Test]
    #[DataProvider('settledRequirements')]
    public function an_inc_satisfied_by_a_separate_source_does_not_block_graduation(string $source): void
    {
        $fixture = $this->fixture();
        $inc = $this->releasedResult($fixture, 'INC');
        if ($source === 'approved credit') {
            $this->approveCredit($fixture);
        } else {
            $this->releasedResult($fixture, '2.00');
        }

        $this->assertSame(CompletionReadinessProjection::ReadyForConferral, app(CompletionReadinessProjection::class)->forStudent($fixture['student'])['state']);
        app(RecordDegreeConferral::class)->execute($fixture['student'], $fixture['registrar'], 'Diploma in Test Operations');

        $this->assertSame('INC', $inc->fresh()->result_code);
        $this->assertSame('INC', $inc->row->fresh()->current_outcome_code);
        $this->assertDatabaseCount('degree_conferrals', 1);
    }

    #[Test]
    public function an_overdue_inc_without_a_satisfying_source_remains_a_curriculum_deficiency(): void
    {
        $fixture = $this->fixture();
        $inc = $this->releasedResult($fixture, 'INC');
        $inc->update(['deadline' => '2028-06-29']);

        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);

        $this->assertSame(CompletionReadinessProjection::NotEligible, $projection['state']);
        $this->assertSame('Deficient', $projection['source_snapshot']['curriculum'][0]['status']);
        $this->assertSame('INC', $inc->fresh()->result_code);
    }

    #[Test]
    #[DataProvider('invalidGraduationDetails')]
    public function invalid_details_create_no_conferral_or_completed_event(string $degree, ?string $date, ?string $honor, string $error): void
    {
        $fixture = $this->fixture();
        $this->approveCredit($fixture);

        try {
            app(RecordDegreeConferral::class)->execute($fixture['student'], $fixture['registrar'], $degree, $date, honorText: $honor);
            $this->fail('Invalid graduation details must not be recorded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($error, $exception->errors());
        }

        $this->assertDatabaseCount('degree_conferrals', 0);
        $this->assertSame(0, StudentLifecycleChange::query()->where('type', StudentLifecycleChange::TypeCompletion)->count());
        $this->assertSame(StudentProfile::LifecycleActive, $fixture['student']->fresh()->lifecycle_status);
    }

    #[Test]
    #[DataProvider('nonRegistrarRoles')]
    public function only_registrar_can_record_graduation(string $role): void
    {
        $fixture = $this->fixture();
        $actor = $this->staff($role);

        try {
            app(RecordDegreeConferral::class)->execute($fixture['student'], $actor, 'Diploma in Test Operations');
            $this->fail('Only the Registrar may record graduation.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only Registrar staff may record degree conferral.', $exception->getMessage());
        }

        $this->assertDatabaseCount('degree_conferrals', 0);
        $this->assertSame(StudentProfile::LifecycleActive, $fixture['student']->fresh()->lifecycle_status);
    }

    public static function settledRequirements(): array
    {
        return ['exact approved credit' => ['approved credit'], 'released passing retake' => ['retake']];
    }

    public static function invalidGraduationDetails(): array
    {
        return [
            'missing degree' => ['   ', null, null, 'conferral'],
            'invalid optional date' => ['Diploma in Test Operations', 'not-a-date', null, 'conferred_on'],
            'honor without its evidence' => ['Diploma in Test Operations', null, 'With distinction', 'honor'],
        ];
    }

    public static function nonRegistrarRoles(): array
    {
        return ['Student' => ['student'], 'Faculty' => [User::StaffRoleFaculty], 'Academic Head' => [User::StaffRoleAcademicHead], 'Accounting' => [User::StaffRoleAccounting], 'System Administrator' => [User::StaffRoleSystemSuperAdmin]];
    }

    /** @return array{student: StudentProfile, registrar: User, entry: CurriculumEntry} */
    private function fixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $student = StudentProfile::factory()->create();
        $specification = CourseSpecification::factory()->create(['academic_classification' => CourseSpecification::AcademicClassificationOrdinary]);
        $entry = CurriculumEntry::factory()->create(['curriculum_version_id' => $student->curriculum_version_id, 'course_specification_id' => $specification->id]);

        return compact('student', 'registrar', 'entry');
    }

    /** @param array{student: StudentProfile, registrar: User, entry: CurriculumEntry} $fixture */
    private function approveCredit(array $fixture): void
    {
        $authority = StudentLifecycleChange::factory()->create(['student_profile_id' => $fixture['student']->id, 'type' => StudentLifecycleChange::TypeProgramShift, 'state' => StudentLifecycleChange::StateApplied]);
        ProgramShiftCreditEntry::factory()->create(['student_lifecycle_change_id' => $authority->id, 'curriculum_entry_id' => $fixture['entry']->id, 'treatment' => ProgramShiftCreditEntry::TreatmentAccepted, 'state' => ProgramShiftCreditEntry::StateRecorded, 'numeric_grade' => '2.00']);
    }

    /** @param array{student: StudentProfile, registrar: User, entry: CurriculumEntry} $fixture */
    private function courseEnrollment(array $fixture): CourseEnrollment
    {
        $term = Term::factory()->create();
        $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $fixture['entry']->id]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $fixture['student']->id, 'credential_user_id' => $fixture['student']->user_id, 'term_id' => $term->id, 'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled, 'status' => 'officially_enrolled', 'officially_enrolled_at' => now()]);

        return CourseEnrollment::factory()->create(['enrollment_id' => $enrollment->id, 'term_offering_id' => $offering->id, 'section_id' => $section->id, 'published_timetable_version_id' => null, 'units_snapshot' => $fixture['entry']->courseSpecification->credit_units]);
    }

    /** @param array{student: StudentProfile, registrar: User, entry: CurriculumEntry} $fixture */
    private function releasedResult(array $fixture, string $result): GradeOutcomeEvent
    {
        $course = $this->courseEnrollment($fixture);
        $roster = GradeRoster::factory()->create(['term_offering_id' => $course->term_offering_id, 'section_id' => $course->section_id, 'state' => GradeRoster::StateReleased]);
        $row = GradeRosterRow::factory()->create(['grade_roster_id' => $roster->id, 'course_enrollment_id' => $course->id, 'current_outcome_code' => $result, 'current_outcome_category' => $result === 'INC' ? GradeRosterRow::CategoryIncomplete : GradeRosterRow::CategoryPassing, 'released_at' => now()]);

        return GradeOutcomeEvent::factory()->create(['grade_roster_row_id' => $row->id, 'result_code' => $result, 'new_category' => $row->current_outcome_category, 'source_term_ends_on' => $course->enrollment->term->ends_on, 'deadline' => $result === 'INC' ? today()->addYear() : null, 'recorded_by' => $fixture['registrar']->id, 'released_at' => now()]);
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
