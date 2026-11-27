<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Actions\Admissions\PublishAdmissionRequirementSet;
use App\Filament\Resources\AdmissionCycles\Pages\CreateAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\EditAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\RelationManagers\RequirementSetsRelationManager;
use App\Filament\Resources\AdmissionCycles\Schemas\AdmissionCycleForm;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue105AdmissionCycleSetupQaTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::findOrCreate('manage-admission-setup', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo($permission);
    }

    public function test_date_validation_uses_friendly_messages_and_blocks_missing_required_dates_upfront(): void
    {
        $registrar = $this->registrar();
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);

        Livewire::test(CreateAdmissionCycle::class)
            ->fillForm([
                'code' => 'QA105-BAD-DATES',
                'label' => 'Invalid dates cycle',
                'term_id' => $term->id,
                'opens_at' => '2027-06-15 08:00:00',
                'closes_at' => '2027-06-10 17:00:00',
                'correction_closes_at' => '2027-06-09 17:00:00',
                'accepted_paths' => [AdmissionCycle::PathFirstYear],
                'programs' => [$program->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['closes_at', 'correction_closes_at'])
            ->assertSee('New applications close must be after Applications open.')
            ->assertDontSee('The closes at field must be a date after opens at.');
    }

    public function test_program_auto_detection_and_readiness_exclude_programs_without_open_curriculum_batch(): void
    {
        Storage::fake('local');
        $registrar = $this->registrar();
        $academicYear = AcademicYear::factory()->create(['state' => AcademicYear::StateActive]);
        $term = Term::factory()->create([
            'academic_year_id' => $academicYear->id,
            'state' => Term::StateActive,
        ]);

        $eligibleProgram = Program::factory()->create(['is_active' => true, 'name' => 'BS Information Technology']);
        $missingBatchProgram = Program::factory()->create(['is_active' => true, 'name' => 'BS Hospitality Management']);
        $inactiveProgram = Program::factory()->create(['is_active' => false, 'name' => 'BS Retired Program']);

        $activeCurriculum = CurriculumVersion::factory()->create([
            'program_id' => $eligibleProgram->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create([
            'program_id' => $eligibleProgram->id,
            'curriculum_version_id' => $activeCurriculum->id,
            'academic_year_id' => $academicYear->id,
        ]);

        $eligibleIds = AdmissionCycleForm::eligibleProgramIdsForTerm($term->id);

        $this->assertSame([$eligibleProgram->id], $eligibleIds);
        $this->assertNotContains($missingBatchProgram->id, $eligibleIds);
        $this->assertNotContains($inactiveProgram->id, $eligibleIds);

        $cycle = AdmissionCycle::factory()->create([
            'term_id' => $term->id,
            'registrar_owner_id' => $registrar->id,
            'opens_at' => now()->addDay(),
            'closes_at' => now()->addDays(10),
            'correction_closes_at' => now()->addDays(12),
        ]);
        $cycle->programs()->attach($missingBatchProgram->id, [
            'accepts_first_year' => true,
            'accepts_transferee' => false,
        ]);

        $projection = app(AdmissionCycleReadinessService::class)->for($cycle->fresh());

        $this->assertFalse($projection['ready']);
        $this->assertContains('accepting_programs', collect($projection['blockers'])->pluck('code')->all());
    }

    public function test_scheduled_cycle_readiness_accepts_published_requirement_set_effective_at_cycle_opening(): void
    {
        Storage::fake('local');
        $registrar = $this->registrar();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);

        $opensAt = now()->addDays(5)->startOfMinute();
        $cycle = AdmissionCycle::factory()->create([
            'term_id' => $term->id,
            'registrar_owner_id' => $registrar->id,
            'opens_at' => $opensAt,
            'closes_at' => $opensAt->copy()->addDays(10),
            'correction_closes_at' => $opensAt->copy()->addDays(12),
        ]);
        $cycle->programs()->attach($program->id, [
            'accepts_first_year' => true,
            'accepts_transferee' => false,
        ]);

        $set = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 1,
            'effective_at' => $opensAt,
        ]);
        AdmissionRequirement::factory()->for($set, 'requirementSet')->create([
            'authority_reference' => null,
            'credential_classification' => AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential,
            'requires_preliminary_evidence' => true,
            'official_submission_method' => AdmissionRequirement::SubmissionInPerson,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
        ]);

        app(PublishAdmissionRequirementSet::class)->execute($set, $registrar);

        $projection = app(AdmissionCycleReadinessService::class)->for($cycle->fresh());

        $this->assertTrue($projection['ready']);
        $this->assertSame([], $projection['blockers']);
    }

    public function test_submission_mode_mapping_and_code_generation_support_both_online_and_f2f(): void
    {
        [$prelim, $official] = RequirementSetsRelationManager::mapSubmissionMode(
            RequirementSetsRelationManager::ModeBothOnlineAndF2f,
        );
        $this->assertTrue($prelim);
        $this->assertSame(AdmissionRequirement::SubmissionInPerson, $official);
        $this->assertSame(
            RequirementSetsRelationManager::ModeBothOnlineAndF2f,
            RequirementSetsRelationManager::resolveSubmissionMode(true, AdmissionRequirement::SubmissionInPerson),
        );

        $code1 = RequirementSetsRelationManager::generateRequirementCode('PSA Birth Certificate');
        $code2 = RequirementSetsRelationManager::generateRequirementCode('PSA Birth Certificate', [$code1]);

        $this->assertSame('REQ-PSA-BIRTH-CERTIFICATE', $code1);
        $this->assertSame('REQ-PSA-BIRTH-CERTIFICATE-2', $code2);
    }

    public function test_edit_admission_cycle_hides_relation_managers_below_save_actions(): void
    {
        $page = new EditAdmissionCycle;

        $this->assertSame([], $page->getRelationManagers());
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);
        $user->saveAppAuthenticationSecret('base32secret3232');
        $user->acknowledgeRecoveryCodeStorage();

        return $user;
    }
}
