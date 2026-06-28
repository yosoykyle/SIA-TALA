<?php

namespace Tests\Feature;

use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentHandoverChecklistHoldTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        foreach (['applicant', 'student', User::StaffRoleRegistrar] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_handover_blocked_by_unresolved_checklist(): void
    {
        [$intake, $registrar] = $this->approvedIntake();
        $this->checklistItem($intake, ChecklistItem::BlockingHandover, ChecklistItem::StatusPending);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Handover is blocked by unresolved BLOCKS_HANDOVER checklist items.');
        app(HandOverApprovedApplicant::class)->execute($intake, $registrar);
    }

    public function test_checklist_morphing_to_student_profile(): void
    {
        [$intake, $registrar] = $this->approvedIntake();
        $source = $this->checklistItem($intake, ChecklistItem::BlockingRetentionOnly, ChecklistItem::StatusPending);

        $studentProfile = app(HandOverApprovedApplicant::class)->execute($intake, $registrar);

        $source->refresh();
        $this->assertSame(ChecklistItem::OwnerApplicant, $source->owner_type);
        $this->assertSame($intake->id, $source->applicant_intake_id);
        $this->assertDatabaseHas('checklist_items', [
            'owner_type' => ChecklistItem::OwnerStudent,
            'applicant_intake_id' => null,
            'student_profile_id' => $studentProfile->id,
            'source_policy_id' => $source->source_policy_id,
        ]);
    }

    public function test_sequential_student_id_format_and_incrementation(): void
    {
        [$firstIntake, $registrar] = $this->approvedIntake();
        [$secondIntake] = $this->approvedIntake($registrar);
        $year = now(config('app.timezone'))->year;

        $first = app(HandOverApprovedApplicant::class)->execute($firstIntake, $registrar);
        $second = app(HandOverApprovedApplicant::class)->execute($secondIntake, $registrar);

        $this->assertMatchesRegularExpression("/^SIA-{$year}-\\d{4}$/", $first->student_number);
        $this->assertSame((int) mb_substr($first->student_number, -4) + 1, (int) mb_substr($second->student_number, -4));
    }

    public function test_returning_student_profile_reuse(): void
    {
        [$intake, $registrar, $program, $curriculum] = $this->approvedIntake(
            category: ApplicantIntake::AdmissionCategoryReturning,
            credentialBasis: ApplicantIntake::CredentialBasisPriorStudentRecord,
        );
        $existingUser = User::factory()->create();
        $existingUser->assignRole('student');
        $existing = StudentProfile::factory()->create([
            'user_id' => $existingUser->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'student_number' => 'SIA-2025-0012',
        ]);

        $result = app(HandOverApprovedApplicant::class)->execute($intake, $registrar, $existing);

        $this->assertTrue($existing->is($result));
        $this->assertSame('SIA-2025-0012', $result->student_number);
        $this->assertSame($intake->program_id, $result->program_id);
        $this->assertSame($intake->fresh()->handed_over_by, $registrar->id);
    }

    public function test_checklist_blocking_during_finance_cleared_handover(): void
    {
        [$intake, $registrar] = $this->approvedIntake();
        $this->checklistItem($intake, ChecklistItem::BlockingEnrollment, ChecklistItem::StatusPending);

        $studentProfile = app(HandOverApprovedApplicant::class)->execute($intake, $registrar);

        $this->assertNotNull($studentProfile->id);
        $this->assertDatabaseHas('checklist_items', [
            'student_profile_id' => $studentProfile->id,
            'blocking_level' => ChecklistItem::BlockingEnrollment,
            'status' => ChecklistItem::StatusPending,
        ]);
    }

    public function test_successful_finance_cleared_handover_when_checklist_resolved(): void
    {
        [$intake, $registrar, , $curriculum] = $this->approvedIntake();
        $this->checklistItem($intake, ChecklistItem::BlockingHandover, ChecklistItem::StatusAccepted);

        $studentProfile = app(HandOverApprovedApplicant::class)->execute($intake, $registrar);

        $this->assertSame($curriculum->id, $studentProfile->curriculum_version_id);
        $this->assertSame(StudentProfile::LifecycleActive, $studentProfile->lifecycle_status);
        $this->assertNotNull($intake->fresh()->handed_over_at);
    }

    public function test_handover_requires_exactly_one_active_curriculum_version(): void
    {
        [$intake, $registrar, $program] = $this->approvedIntake();
        CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exactly one active curriculum version');
        app(HandOverApprovedApplicant::class)->execute($intake, $registrar);
    }

    public function test_repeated_handover_is_idempotent(): void
    {
        [$intake, $registrar] = $this->approvedIntake();
        $action = app(HandOverApprovedApplicant::class);

        $first = $action->execute($intake, $registrar);
        $second = $action->execute($intake->fresh(), $registrar);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, StudentProfile::query()->where('applicant_intake_id', $intake->id)->count());
    }

    /** @return array{ApplicantIntake, User, Program, CurriculumVersion} */
    private function approvedIntake(
        ?User $registrar = null,
        string $category = ApplicantIntake::AdmissionCategoryFirstTimeCollege,
        string $credentialBasis = ApplicantIntake::CredentialBasisSeniorHighSchool,
    ): array {
        $registrar ??= User::factory()->create(['status' => User::StatusActive]);

        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            $registrar->assignRole(User::StaffRoleRegistrar);
        }

        $applicant = User::factory()->create(['status' => User::StatusApplicantApproved]);
        $applicant->assignRole('applicant');
        $program = Program::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'admission_category' => $category,
            'credential_basis' => $credentialBasis,
        ]);

        return [$intake, $registrar, $program, $curriculum];
    }

    private function checklistItem(ApplicantIntake $intake, string $blockingLevel, string $status): ChecklistItem
    {
        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_category' => $intake->admission_category,
            'credential_basis' => $intake->credential_basis,
            'blocking_level' => $blockingLevel,
        ]);

        return ChecklistItem::factory()->create([
            'owner_type' => ChecklistItem::OwnerApplicant,
            'applicant_intake_id' => $intake->id,
            'student_profile_id' => null,
            'source_policy_id' => $policy->id,
            'blocking_level' => $blockingLevel,
            'status' => $status,
        ]);
    }
}
