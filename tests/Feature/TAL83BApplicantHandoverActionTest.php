<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL83BApplicantHandoverActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, 'applicant', 'student'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Permission::findOrCreate('approve-documents', 'web');
        Permission::findOrCreate('evaluate-transferees', 'web');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_registrar_can_handover_approved_applicant_from_review_page(): void
    {
        [$intake, $registrar, $applicant, , $curriculum] = $this->approvedIntake();

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->getRouteKey()])
            ->assertActionVisible('handOverToStudent')
            ->callAction('handOverToStudent')
            ->assertNotified('Applicant handed over to Student Hub');

        $studentProfile = StudentProfile::query()->where('applicant_intake_id', $intake->id)->firstOrFail();

        $this->assertNotNull($intake->fresh()->handed_over_at);
        $this->assertSame($registrar->id, $intake->fresh()->handed_over_by);
        $this->assertSame($applicant->id, $studentProfile->user_id);
        $this->assertSame($curriculum->id, $studentProfile->curriculum_version_id);
        $this->assertSame(StudentProfile::LifecycleActive, $studentProfile->lifecycle_status);
        $this->assertMatchesRegularExpression('/^SIA-'.now(config('app.timezone'))->year.'-\d{4}$/', $studentProfile->student_number);

        $studentUser = $applicant->fresh();
        $this->assertSame(User::StatusActive, $studentUser->status);
        $this->assertTrue($studentUser->hasRole('student'));
        $this->assertTrue($studentUser->canAccessPanel(Filament::getPanel('student')));
    }

    public function test_handover_action_carries_forward_non_handover_checklist_items(): void
    {
        [$intake, $registrar] = $this->approvedIntake();
        $source = $this->checklistItem($intake, ChecklistItem::BlockingRetentionOnly, ChecklistItem::StatusPending);

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->getRouteKey()])
            ->callAction('handOverToStudent')
            ->assertNotified('Applicant handed over to Student Hub');

        $studentProfile = StudentProfile::query()->where('applicant_intake_id', $intake->id)->firstOrFail();

        $this->assertDatabaseHas('checklist_items', [
            'owner_type' => ChecklistItem::OwnerStudent,
            'applicant_intake_id' => null,
            'student_profile_id' => $studentProfile->id,
            'source_policy_id' => $source->source_policy_id,
            'blocking_level' => ChecklistItem::BlockingRetentionOnly,
            'status' => ChecklistItem::StatusPending,
        ]);
    }

    public function test_unresolved_blocks_handover_checklist_item_blocks_ui_handover(): void
    {
        [$intake, $registrar] = $this->approvedIntake();
        $this->checklistItem($intake, ChecklistItem::BlockingHandover, ChecklistItem::StatusPending);

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->getRouteKey()])
            ->assertActionVisible('handOverToStudent')
            ->callAction('handOverToStudent')
            ->assertNotified('Applicant handover blocked');

        $this->assertNull($intake->fresh()->handed_over_at);
        $this->assertSame(0, StudentProfile::query()->where('applicant_intake_id', $intake->id)->count());
    }

    public function test_missing_active_curriculum_blocks_ui_handover(): void
    {
        [$intake, $registrar] = $this->approvedIntake(createActiveCurriculum: false);

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->getRouteKey()])
            ->callAction('handOverToStudent')
            ->assertNotified('Applicant handover blocked');

        $this->assertNull($intake->fresh()->handed_over_at);
        $this->assertSame(0, StudentProfile::query()->where('applicant_intake_id', $intake->id)->count());
    }

    public function test_non_approved_and_already_handed_over_intakes_hide_handover_action(): void
    {
        [$approvedIntake, $registrar] = $this->approvedIntake();
        $pendingIntake = ApplicantIntake::factory()->create([
            'program_id' => $approvedIntake->program_id,
            'term_id' => $approvedIntake->term_id,
            'status' => ApplicantIntake::StatusPending,
            'approved_at' => null,
            'approved_by' => null,
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $pendingIntake->getRouteKey()])
            ->assertActionHidden('handOverToStudent');

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $approvedIntake->getRouteKey()])
            ->callAction('handOverToStudent')
            ->assertNotified('Applicant handed over to Student Hub');

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $approvedIntake->fresh()->getRouteKey()])
            ->assertActionHidden('handOverToStudent');
    }

    public function test_staff_without_registrar_role_cannot_handover_even_with_admissions_permission(): void
    {
        [$intake] = $this->approvedIntake();
        $accounting = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $accounting->assignRole(User::StaffRoleAccounting);
        $accounting->givePermissionTo('approve-documents');

        Livewire::actingAs($accounting)
            ->test(ViewApplicantIntake::class, ['record' => $intake->getRouteKey()])
            ->assertActionHidden('handOverToStudent');
    }

    /**
     * @return array{ApplicantIntake, User, User, Program, ?CurriculumVersion}
     */
    private function approvedIntake(bool $createActiveCurriculum = true): array
    {
        $registrar = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo('approve-documents');

        $applicant = User::factory()->create([
            'status' => User::StatusApplicantApproved,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        $program = Program::factory()->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $curriculum = $createActiveCurriculum
            ? CurriculumVersion::factory()->create([
                'program_id' => $program->id,
                'state' => CurriculumVersion::StateActive,
            ])
            : null;

        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
            'program_id' => $program->id,
            'term_id' => $term->id,
        ]);

        return [$intake, $registrar, $applicant, $program, $curriculum];
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
