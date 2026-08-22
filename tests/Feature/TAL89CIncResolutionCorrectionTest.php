<?php

namespace Tests\Feature;

use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\ReleaseIncCompletion;
use App\Actions\Grades\SubmitIncCompletion;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\GradeRosters\RelationManagers\RowsRelationManager;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL89CIncResolutionCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function faculty_submits_and_registrar_releases_inc_completion_with_successor_audit(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $nextCase = $this->pendingRegistrationCaseFor($roster);

        $incomplete = $row->outcomeEvents()->sole();
        $submission = app(SubmitIncCompletion::class)->execute(
            $incomplete,
            '3.00',
            'Completed removal exam.',
            $faculty,
        );
        $released = app(ReleaseIncCompletion::class)->execute($submission, $registrar, 'INC-001');

        $row->refresh();

        $this->assertSame('3.00', $row->current_outcome_code);
        $this->assertSame(GradeRosterRow::CategoryPassing, $row->current_outcome_category);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeIncResolution,
            'predecessor_event_id' => $incomplete->id,
            'previous_category' => GradeRosterRow::CategoryIncomplete,
            'new_category' => GradeRosterRow::CategoryPassing,
            'authority' => 'INC-001',
            'reason' => 'Completed removal exam.',
            'recorded_by' => $registrar->id,
        ]);
        $this->assertSame($released->id, $submission->fresh()->released_event_id);
        $this->assertDatabaseHas('registration_case_events', [
            'enrollment_id' => $nextCase->id,
            'event_type' => 'AcademicResultImpactReviewOpened',
            'actor_id' => $registrar->id,
        ]);
    }

    #[Test]
    public function overdue_inc_remains_inc_and_cannot_be_completed_without_an_amendment(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $incomplete = $row->outcomeEvents()->sole();
        $incomplete->update(['deadline' => today()->subDay()]);

        try {
            app(SubmitIncCompletion::class)->execute($incomplete->fresh(), '5.00', 'Late work.', $faculty);
            $this->fail('Deadline passage must not permit completion or convert the INC automatically.');
        } catch (RuntimeException) {
            $this->assertSame('INC', $row->fresh()->current_outcome_code);
            $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            $this->assertDatabaseCount('inc_completion_submissions', 0);
        }
    }

    #[Test]
    public function inc_completion_on_non_inc_row_is_rejected(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();

        try {
            app(SubmitIncCompletion::class)->execute(
                $row->outcomeEvents()->sole(), '3.00', 'Completion must require an INC.', $faculty,
            );
            $this->fail('INC completion on a non-INC row should be rejected.');
        } catch (RuntimeException) {
            $row->refresh();
            $this->assertSame('1.75', $row->current_outcome_code);
            $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            $this->assertSame(0, IncCompletionSubmission::query()->count());
        }
    }

    #[Test]
    public function registrar_records_posted_correction_on_released_row_and_preserves_previous_grade(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();
        $nextCase = $this->pendingRegistrationCaseFor($roster);

        app(RecordApprovedGradeCorrection::class)->execute($row, '2.75', 'Approved correction form', 'Physical correction approved.', 'CORR-001', $registrar);

        $row->refresh();

        $this->assertSame('2.75', $row->current_outcome_code);
        $this->assertSame(GradeRosterRow::CategoryPassing, $row->current_outcome_category);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypePostedCorrection,
            'previous_value' => '1.7500',
            'previous_category' => GradeRosterRow::CategoryPassing,
            'evidence_reference' => 'CORR-001',
            'recorded_by' => $registrar->id,
        ]);
        $this->assertDatabaseHas('registration_case_events', [
            'enrollment_id' => $nextCase->id,
            'event_type' => 'AcademicResultImpactReviewOpened',
            'actor_id' => $registrar->id,
        ]);
    }

    #[Test]
    public function posted_correction_on_unreleased_row_is_rejected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);
        $row = $roster->rows()->sole();

        try {
            app(RecordApprovedGradeCorrection::class)->execute($row, '2.75', 'Authority', 'Reason', null, $registrar);
            $this->fail('Posted correction on an unreleased row should be rejected.');
        } catch (RuntimeException) {
            $row->refresh();
            $this->assertNull($row->released_at);
            $this->assertSame(0, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
        }
    }

    #[Test]
    public function non_registrar_staff_cannot_release_inc_completion_through_the_service(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        $submission = app(SubmitIncCompletion::class)->execute(
            $row->outcomeEvents()->sole(), '3.00', 'Completed authorized work.', $faculty,
        );

        foreach ([$faculty, $academicHead] as $actor) {
            try {
                app(ReleaseIncCompletion::class)->execute($submission->fresh(), $actor, 'INC-AUTH-001');
                $this->fail('Only Registrar staff should release INC completion.');
            } catch (AuthorizationException) {
                $this->assertSame('INC', $row->fresh()->current_outcome_code);
                $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            }
        }
    }

    #[Test]
    public function non_registrar_staff_cannot_record_posted_correction_through_the_service(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();

        foreach ([$faculty, $academicHead] as $actor) {
            try {
                app(RecordApprovedGradeCorrection::class)->execute($row->fresh(), '2.75', 'Authority', 'Reason', null, $actor);
                $this->fail('Only Registrar staff should record posted corrections.');
            } catch (AuthorizationException) {
                $this->assertSame('1.75', $row->fresh()->current_outcome_code);
                $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
            }
        }
    }

    #[Test]
    public function rows_relation_manager_is_visible_to_registrar_on_released_roster_only(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $releasedRoster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $submittedRoster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);

        $this->actingAs($registrar);
        $this->assertTrue(RowsRelationManager::canViewForRecord($releasedRoster, ViewGradeRoster::class));
        $this->assertFalse(RowsRelationManager::canViewForRecord($submittedRoster, ViewGradeRoster::class));

        $this->actingAs($academicHead);
        $this->assertTrue(RowsRelationManager::canViewForRecord($releasedRoster, ViewGradeRoster::class));
    }

    #[Test]
    public function registrar_releases_submitted_inc_completion_through_relation_manager_action(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, 'INC', GradeRosterRow::CategoryIncomplete);
        $row = $roster->rows()->sole();
        app(SubmitIncCompletion::class)->execute(
            $row->outcomeEvents()->sole(), '3.00', 'Completed removal exam.', $faculty,
        );

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RowsRelationManager::class, [
            'ownerRecord' => $roster,
            'pageClass' => ViewGradeRoster::class,
        ])
            ->assertTableActionVisible('releaseIncCompletion', $row)
            ->assertTableActionVisible('recordCorrection', $row)
            ->callTableAction('releaseIncCompletion', $row, data: [
                'authority_reference' => 'INC-010',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('INC completion released');

        $row->refresh();
        $this->assertSame('3.00', $row->current_outcome_code);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeIncResolution,
            'authority' => 'INC-010',
            'recorded_by' => $registrar->id,
        ]);
    }

    #[Test]
    public function registrar_records_correction_through_relation_manager_action(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->releasedRosterWithRow($faculty, '1.75', GradeRosterRow::CategoryPassing);
        $row = $roster->rows()->sole();

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(RowsRelationManager::class, [
            'ownerRecord' => $roster,
            'pageClass' => ViewGradeRoster::class,
        ])
            ->assertTableActionVisible('recordCorrection', $row)
            ->assertTableActionHidden('releaseIncCompletion', $row)
            ->callTableAction('recordCorrection', $row, data: [
                'corrected_code' => '2.75',
                'authority' => 'Approved correction form',
                'reason' => 'Physical correction approved.',
                'evidence_reference' => 'CORR-010',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Authorized correction recorded');

        $row->refresh();
        $this->assertSame('2.75', $row->current_outcome_code);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypePostedCorrection,
            'evidence_reference' => 'CORR-010',
            'recorded_by' => $registrar->id,
        ]);
    }

    private function releasedRosterWithRow(User $faculty, string $code, string $category): GradeRoster
    {
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateReleased, submittedBy: $faculty);
        $roster->update([
            'reviewed_by' => $faculty->id,
            'reviewed_at' => now(),
            'released_by' => $faculty->id,
            'released_at' => now(),
        ]);

        $row = $roster->rows()->sole();
        $row->update([
            'current_outcome_code' => $code,
            'current_outcome_category' => $category,
            'released_at' => now(),
        ]);
        GradeOutcomeEvent::query()->create([
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => $code,
            'source_term_ends_on' => $roster->termOffering->term->ends_on,
            'previous_category' => null,
            'new_category' => $category,
            'new_value' => is_numeric($code) ? (float) $code : null,
            'deadline' => $code === 'INC' ? today()->addYear()->toDateString() : null,
            'inc_completion_note' => $code === 'INC' ? 'Complete the remaining authorized work.' : null,
            'authority' => 'TEST-INITIAL-RELEASE',
            'reason' => 'Synthetic released-result fixture.',
            'recorded_by' => $faculty->id,
            'released_at' => now(),
            'source_key' => 'test-initial-release:'.$row->id,
        ]);

        return $roster->fresh(['rows']);
    }

    private function rosterWithRow(
        User $faculty,
        string $state,
        ?User $submittedBy = null,
        string $studentLastName = 'Student',
    ): GradeRoster {
        $termOffering = TermOffering::factory()->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->create(['term_offering_id' => $termOffering->id]);
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'last_name' => $studentLastName,
        ]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'program_id' => $termOffering->curriculumEntry->curriculumVersion->program_id,
            'curriculum_version_id' => $termOffering->curriculumEntry->curriculum_version_id,
            'last_name' => $studentLastName,
        ]);
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $student->id,
            'student_profile_id' => $profile->id,
            'term_id' => $termOffering->term_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $termOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $assignment = ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $termOffering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'TEST-TEACHING-ASSIGNMENT',
            'assigned_by' => $faculty->id,
            'effective_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $termOffering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'teaching_assignment_id' => $assignment->id,
            'state' => $state,
            'submitted_by' => $submittedBy?->id,
            'submitted_at' => $submittedBy === null ? null : now(),
        ]);

        GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'prelim_equivalent' => 90,
            'midterm_equivalent' => 91,
            'final_equivalent' => 94,
            'computed_average' => 91.9,
        ]);

        return $roster;
    }

    private function pendingRegistrationCaseFor(GradeRoster $roster): Enrollment
    {
        $source = $roster->rows()->sole()->courseEnrollment->enrollment;
        $sourceCourse = $roster->termOffering->curriculumEntry->courseSpecification->course;
        $dependentTerm = Term::factory()->create([
            'starts_on' => $source->term->starts_on->addMonths(5),
            'ends_on' => $source->term->ends_on->addMonths(5),
        ]);
        $dependentSpecification = CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateActive,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $dependentSpecification->id,
            'related_course_id' => $sourceCourse->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'state' => CourseRequirement::StateActive,
        ]);
        $dependentEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $source->studentProfile->curriculum_version_id,
            'course_specification_id' => $dependentSpecification->id,
        ]);
        $dependentOffering = TermOffering::factory()->create([
            'term_id' => $dependentTerm->id,
            'curriculum_entry_id' => $dependentEntry->id,
            'state' => TermOffering::StateScheduled,
        ]);

        $nextCase = Enrollment::factory()->create([
            'credential_user_id' => $source->credential_user_id,
            'student_profile_id' => $source->student_profile_id,
            'term_id' => $dependentTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'status' => 'pending',
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $nextCase->id,
            'term_offering_id' => $dependentOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);

        return $nextCase;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
