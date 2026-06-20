<?php

namespace Tests\Feature;

use App\Filament\Resources\AdmissionCapacityPlans\AdmissionCapacityPlanResource;
use App\Filament\Resources\AdmissionOfferings\AdmissionOfferingResource;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\DocumentRequirementItems\DocumentRequirementItemResource;
use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\DocumentRequirementItem;
use App\Models\User;
use App\Policies\AdmissionCapacityPlanPolicy;
use App\Policies\AdmissionOfferingPolicy;
use App\Policies\AdmissionRequirementPolicyPolicy;
use App\Policies\DocumentRequirementItemPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SDD07AAdmissionSetupFilamentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::staffRoleNames() as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_registrar_can_open_admission_setup_surfaces(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['manage-admission-setup']);

        $this->actingAs($registrar);

        foreach ($this->admissionSetupUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_ordinary_staff_cannot_open_admission_setup_surfaces(): void
    {
        $staff = $this->staffUser(User::StaffRoleFaculty, []);

        $this->actingAs($staff);

        $this->get(AdmissionOfferingResource::getUrl('index'))->assertForbidden();
        $this->get(AdmissionCapacityPlanResource::getUrl('create'))->assertForbidden();
    }

    public function test_registrar_can_open_admission_setup_detail_pages(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['manage-admission-setup']);
        $offering = AdmissionOffering::factory()->create();
        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_offering_id' => $offering->id,
        ]);
        $item = DocumentRequirementItem::factory()->create([
            'admission_requirement_policy_id' => $policy->id,
        ]);
        $plan = AdmissionCapacityPlan::factory()->create([
            'term_id' => $offering->term_id,
        ]);

        $this->actingAs($registrar);

        foreach ([
            AdmissionOfferingResource::getUrl('view', ['record' => $offering]),
            AdmissionRequirementPolicyResource::getUrl('view', ['record' => $policy]),
            DocumentRequirementItemResource::getUrl('view', ['record' => $item]),
            AdmissionCapacityPlanResource::getUrl('view', ['record' => $plan]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admission_setup_resources_use_registrar_navigation_typed_fields_and_no_delete_actions(): void
    {
        $resources = [
            'AdmissionOfferings/AdmissionOfferingResource.php',
            'AdmissionRequirementPolicies/AdmissionRequirementPolicyResource.php',
            'DocumentRequirementItems/DocumentRequirementItemResource.php',
            'AdmissionCapacityPlans/AdmissionCapacityPlanResource.php',
        ];

        foreach ($resources as $resource) {
            $this->assertStringContainsString("'Registrar'", $this->resourceSource($resource));
        }

        $offeringForm = $this->resourceSource('AdmissionOfferings/Schemas/AdmissionOfferingForm.php');
        $policyForm = $this->resourceSource('AdmissionRequirementPolicies/Schemas/AdmissionRequirementPolicyForm.php');
        $itemForm = $this->resourceSource('DocumentRequirementItems/Schemas/DocumentRequirementItemForm.php');
        $capacityForm = $this->resourceSource('AdmissionCapacityPlans/Schemas/AdmissionCapacityPlanForm.php');

        foreach ([
            "Select::make('term_id')",
            "Select::make('education_level')",
            "Select::make('entry_route')",
            "Select::make('status')",
            "DateTimePicker::make('published_at')",
        ] as $field) {
            $this->assertStringContainsString($field, $offeringForm);
        }

        foreach ([
            "Select::make('admission_offering_id')",
            "TextInput::make('version')",
            "DateTimePicker::make('approved_at')",
        ] as $field) {
            $this->assertStringContainsString($field, $policyForm);
        }

        foreach ([
            "Select::make('admission_requirement_policy_id')",
            "CheckboxList::make('permitted_evidence_methods')",
            "Select::make('gate_type')",
            "Select::make('storage_class')",
            "Select::make('sensitivity_class')",
            "Select::make('ocr_policy')",
        ] as $field) {
            $this->assertStringContainsString($field, $itemForm);
        }

        foreach ([
            "Select::make('scope_type')",
            "TextInput::make('capacity_limit')",
            "TextInput::make('reserved_count')",
            '->dehydrated(false)',
        ] as $field) {
            $this->assertStringContainsString($field, $capacityForm);
        }

        foreach ([
            'AdmissionOfferings/Tables/AdmissionOfferingsTable.php',
            'AdmissionRequirementPolicies/Tables/AdmissionRequirementPoliciesTable.php',
            'DocumentRequirementItems/Tables/DocumentRequirementItemsTable.php',
            'AdmissionCapacityPlans/Tables/AdmissionCapacityPlansTable.php',
            'AdmissionOfferings/Pages/EditAdmissionOffering.php',
            'AdmissionRequirementPolicies/Pages/EditAdmissionRequirementPolicy.php',
            'DocumentRequirementItems/Pages/EditDocumentRequirementItem.php',
            'AdmissionCapacityPlans/Pages/EditAdmissionCapacityPlan.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringNotContainsString('DeleteAction::make()', $source);
            $this->assertStringNotContainsString('DeleteBulkAction::make()', $source);
        }
    }

    public function test_admission_setup_policies_allow_setup_managers_and_global_viewers_without_delete(): void
    {
        $manager = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'manage-admission-setup';
            }
        };
        $viewer = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'view-global-records';
            }
        };
        $blocked = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return false;
            }
        };

        foreach ($this->policyRecordPairs() as [$policy, $record]) {
            $this->assertTrue($policy->viewAny($manager));
            $this->assertTrue($policy->create($manager));
            $this->assertTrue($policy->update($manager, $record));
            $this->assertFalse($policy->delete($manager, $record));

            $this->assertTrue($policy->viewAny($viewer));
            $this->assertFalse($policy->create($viewer));
            $this->assertFalse($policy->update($viewer, $record));

            $this->assertFalse($policy->viewAny($blocked));
        }
    }

    public function test_database_seeder_assigns_admission_setup_permission_to_registrar(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'manage-admission-setup'", $source);
        $this->assertStringContainsString("Role::findByName('registrar')->syncPermissions", $source);
    }

    /**
     * @return list<string>
     */
    private function admissionSetupUrls(): array
    {
        return [
            AdmissionOfferingResource::getUrl('index'),
            AdmissionOfferingResource::getUrl('create'),
            AdmissionRequirementPolicyResource::getUrl('index'),
            AdmissionRequirementPolicyResource::getUrl('create'),
            DocumentRequirementItemResource::getUrl('index'),
            DocumentRequirementItemResource::getUrl('create'),
            AdmissionCapacityPlanResource::getUrl('index'),
            AdmissionCapacityPlanResource::getUrl('create'),
        ];
    }

    /**
     * @return list<array{0: object, 1: object}>
     */
    private function policyRecordPairs(): array
    {
        return [
            [app(AdmissionOfferingPolicy::class), new AdmissionOffering],
            [app(AdmissionRequirementPolicyPolicy::class), new AdmissionRequirementPolicy],
            [app(DocumentRequirementItemPolicy::class), new DocumentRequirementItem],
            [app(AdmissionCapacityPlanPolicy::class), new AdmissionCapacityPlan],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
