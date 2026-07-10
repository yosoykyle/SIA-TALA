<?php

namespace Tests\Feature;

use App\Actions\Applicants\AdmissionRequirementResolver;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\AdmissionRequirementPolicies\Pages\CreateAdmissionRequirementPolicy;
use App\Filament\Resources\AdmissionRequirementPolicies\Pages\EditAdmissionRequirementPolicy;
use App\Filament\Resources\AdmissionRequirementPolicies\Pages\ListAdmissionRequirementPolicies;
use App\Filament\Resources\AdmissionRequirementPolicies\Pages\ViewAdmissionRequirementPolicy;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TAL-93I: the staff-configurable admission-requirement management surface built on
 * the live state-based model. Authority: PRD §13.1.1 (records #6/#7/#8, rules 6-8),
 * §13.8 interaction contract, and 03_admissions_student_handover.md §3.1.
 */
final class TAL93IAdmissionRequirementPolicyConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'demo_tala_db',
            'tala_test_codex',
        ]);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_resource_routes_are_registered_and_registrar_can_render_all_pages(): void
    {
        $registrar = $this->userWithRole('registrar');
        $policy = AdmissionRequirementPolicy::factory()->create();

        $this->assertTrue(Route::has('filament.admin.resources.admission-requirement-policies.index'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-requirement-policies.create'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-requirement-policies.view'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-requirement-policies.edit'));

        Livewire::actingAs($registrar)
            ->test(ListAdmissionRequirementPolicies::class)
            ->assertCanSeeTableRecords([$policy]);

        Livewire::actingAs($registrar)
            ->test(CreateAdmissionRequirementPolicy::class)
            ->assertSuccessful();

        Livewire::actingAs($registrar)
            ->test(ViewAdmissionRequirementPolicy::class, ['record' => $policy->getRouteKey()])
            ->assertSuccessful();

        Livewire::actingAs($registrar)
            ->test(EditAdmissionRequirementPolicy::class, ['record' => $policy->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_registrar_can_create_and_then_edit_a_policy(): void
    {
        $registrar = $this->userWithRole('registrar');

        Livewire::actingAs($registrar)
            ->test(CreateAdmissionRequirementPolicy::class)
            ->fillForm([
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'requirement_type' => 'IDENTITY_DOCUMENT',
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'blocking_level' => ChecklistItem::BlockingHandover,
                'state' => AdmissionRequirementPolicy::StateActive,
                'effective_from' => '2026-06-01',
                'effective_until' => null,
                'authority' => 'Registrar admissions policy 2026',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admission_requirement_policies', [
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'state' => AdmissionRequirementPolicy::StateActive,
            'authority' => 'Registrar admissions policy 2026',
        ]);

        $policy = AdmissionRequirementPolicy::query()->latest('id')->firstOrFail();

        Livewire::actingAs($registrar)
            ->test(EditAdmissionRequirementPolicy::class, ['record' => $policy->getRouteKey()])
            ->assertFormSet([
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'authority' => 'Registrar admissions policy 2026',
            ])
            ->fillForm(['authority' => 'Registrar admissions policy 2026 (rev B)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Registrar admissions policy 2026 (rev B)', $policy->fresh()->authority);
    }

    public function test_registrar_can_transition_policy_from_draft_to_active_to_superseded(): void
    {
        $registrar = $this->userWithRole('registrar');
        $policy = AdmissionRequirementPolicy::factory()->create([
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'state' => AdmissionRequirementPolicy::StateDraft,
            'effective_until' => null,
        ]);

        Livewire::actingAs($registrar)
            ->test(EditAdmissionRequirementPolicy::class, ['record' => $policy->getRouteKey()])
            ->fillForm(['state' => AdmissionRequirementPolicy::StateActive])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AdmissionRequirementPolicy::StateActive, $policy->fresh()->state);

        Livewire::actingAs($registrar)
            ->test(EditAdmissionRequirementPolicy::class, ['record' => $policy->getRouteKey()])
            ->fillForm([
                'state' => AdmissionRequirementPolicy::StateSuperseded,
                'effective_until' => '2026-12-31',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AdmissionRequirementPolicy::StateSuperseded, $policy->fresh()->state);
        $this->assertDatabaseHas('admission_requirement_policies', [
            'id' => $policy->id,
            'state' => AdmissionRequirementPolicy::StateSuperseded,
            'effective_until' => '2026-12-31',
        ]);
    }

    #[DataProvider('roleAccessProvider')]
    public function test_admission_requirement_policy_access_matches_role(string $role, bool $expected): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame(
            $expected,
            AdmissionRequirementPolicyResource::canAccess(),
            "{$role} access to the admission requirement policy surface must match the canonical matrix.",
        );
        $this->assertSame(
            $expected,
            Gate::forUser($user)->allows('viewAny', AdmissionRequirementPolicy::class),
            "{$role} viewAny authorization must match the canonical matrix.",
        );
    }

    public function test_admission_requirement_policy_can_never_be_deleted(): void
    {
        $registrar = $this->userWithRole('registrar');
        $policy = AdmissionRequirementPolicy::factory()->create();

        $this->assertFalse(Gate::forUser($registrar)->allows('delete', $policy));
        $this->assertFalse(Gate::forUser($registrar)->allows('forceDelete', $policy));
        $this->assertFalse(Gate::forUser($registrar)->allows('restore', $policy));
    }

    public function test_option_helpers_stay_aligned_with_consuming_vocabulary(): void
    {
        $this->assertSame(
            [
                ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                ApplicantIntake::AdmissionCategoryTransfer,
                ApplicantIntake::AdmissionCategoryReturning,
            ],
            array_keys(AdmissionRequirementPolicy::admissionCategoryOptions()),
        );

        $this->assertSame(
            [
                ApplicantIntake::CredentialBasisSeniorHighSchool,
                ApplicantIntake::CredentialBasisTransferCredentials,
                ApplicantIntake::CredentialBasisPriorStudentRecord,
            ],
            array_keys(AdmissionRequirementPolicy::credentialBasisOptions()),
        );

        $this->assertSame(
            [
                ChecklistItem::BlockingHandover,
                ChecklistItem::BlockingEnrollment,
                ChecklistItem::BlockingCorPrint,
                ChecklistItem::BlockingRecordRelease,
                ChecklistItem::BlockingRetentionOnly,
                ChecklistItem::BlockingAdvisoryOnly,
            ],
            array_keys(AdmissionRequirementPolicy::blockingLevelOptions()),
        );

        $this->assertSame(
            [
                ChecklistItem::EvidenceMethodPhysicalCopy,
                ChecklistItem::EvidenceMethodDigitalUpload,
                ChecklistItem::EvidenceMethodMetadataOnly,
            ],
            array_keys(AdmissionRequirementPolicy::evidenceMethodOptions()),
        );

        $this->assertArrayHasKey('IDENTITY_DOCUMENT', AdmissionRequirementPolicy::requirementTypeOptions());
    }

    public function test_active_policy_is_returned_by_admission_requirement_resolver(): void
    {
        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
            'credential_basis' => ApplicantIntake::CredentialBasisTransferCredentials,
            'requirement_type' => 'IDENTITY_DOCUMENT',
            'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
            'blocking_level' => ChecklistItem::BlockingHandover,
            'state' => AdmissionRequirementPolicy::StateActive,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_until' => null,
        ]);

        $intake = new ApplicantIntake([
            'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
            'credential_basis' => ApplicantIntake::CredentialBasisTransferCredentials,
        ]);

        $resolved = app(AdmissionRequirementResolver::class)->resolve($intake);

        $this->assertTrue(
            $resolved->contains(fn (AdmissionRequirementPolicy $candidate): bool => $candidate->is($policy)),
            'The active policy for the matching category and credential basis must be resolved.',
        );
    }

    /**
     * @return array<string, array{role: string, expected: bool}>
     */
    public static function roleAccessProvider(): array
    {
        return [
            'registrar can manage admission setup' => ['role' => 'registrar', 'expected' => true],
            'accounting cannot' => ['role' => 'accounting', 'expected' => false],
            'faculty cannot' => ['role' => 'faculty', 'expected' => false],
            'academic head cannot' => ['role' => 'academic-head', 'expected' => false],
            'student cannot' => ['role' => 'student', 'expected' => false],
            'applicant cannot' => ['role' => 'applicant', 'expected' => false],
            'system super admin has no blanket bypass' => ['role' => 'system-super-admin', 'expected' => false],
        ];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
