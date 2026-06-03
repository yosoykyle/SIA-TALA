<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\SystemSettingPolicy;
use Tests\TestCase;

class TAL12ASystemSuperAdminFilamentResourceTest extends TestCase
{
    public function test_user_management_form_uses_staff_account_fields_and_hides_system_managed_fields(): void
    {
        $source = $this->source('Users/Schemas/UserForm.php');

        foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'username', 'email', 'password', 'roles'] as $field) {
            $this->assertStringContainsString("'{$field}'", $source);
        }

        foreach (['email_verified_at', 'archived_at', 'archived_reason', 'remember_token'] as $systemManagedField) {
            $this->assertStringNotContainsString("TextInput::make('{$systemManagedField}')", $source);
            $this->assertStringNotContainsString("DateTimePicker::make('{$systemManagedField}')", $source);
        }

        $this->assertStringContainsString('System Super Admin creates staff accounts only', $source);
    }

    public function test_system_settings_are_internal_runtime_registry_not_generic_admin_crud(): void
    {
        $resource = $this->source('SystemSettings/SystemSettingResource.php');
        $policy = file_get_contents(app_path('Policies/SystemSettingPolicy.php'));
        $model = file_get_contents(app_path('Models/SystemSetting.php'));

        $this->assertIsString($policy);
        $this->assertIsString($model);

        $this->assertStringContainsString('protected static bool $shouldRegisterNavigation = false;', $resource);
        $this->assertStringContainsString('return false;', $resource);
        $this->assertStringContainsString('public function viewAny(User $user): bool', $policy);
        $this->assertStringContainsString('public function update(User $user, SystemSetting $model): bool', $policy);
        $this->assertStringNotContainsString('return $user->can(\'manage-settings\')', $policy);
        $this->assertStringContainsString('Internal seeded JSON only for this phase', $model);

        $policyInstance = app(SystemSettingPolicy::class);
        $user = new User;
        $setting = new SystemSetting(['key' => 'maintenance_mode']);

        $this->assertFalse($policyInstance->viewAny($user));
        $this->assertFalse($policyInstance->view($user, $setting));
        $this->assertFalse($policyInstance->update($user, $setting));

        foreach ([
            'maintenance_mode',
            'admission_requirements',
            'installment_policy_defaults',
            'shs_cutover_effective_term',
            'shs_cutover_effective_datetime',
            'college_cutover_effective_term',
            'college_cutover_effective_datetime',
        ] as $key) {
            $this->assertArrayHasKey($key, SystemSetting::SettingDefinitions);
        }

        $this->assertFalse(SystemSetting::SettingDefinitions['admission_requirements']['editable']);
    }

    public function test_faq_entries_use_fixed_categories_and_super_admin_owned_authoring_fields(): void
    {
        $form = $this->source('FaqEntries/Schemas/FaqEntryForm.php');
        $model = file_get_contents(app_path('Models/FaqEntry.php'));
        $policy = file_get_contents(app_path('Policies/FaqEntryPolicy.php'));

        $this->assertIsString($model);
        $this->assertIsString($policy);
        $this->assertStringContainsString('manage-faqs', $policy);

        foreach (['General', 'Admission / Enrollment', 'Payments / Fees', 'Documents / Requests', 'Grades / Academics', 'Account / Login', 'Technical Support'] as $category) {
            $this->assertStringContainsString($category, $model);
        }

        $this->assertStringContainsString('FaqEntry::categoryOptions()', $form);
        $this->assertStringContainsString('created_by', $model);
        $this->assertStringContainsString('updated_by', $model);
        $this->assertStringNotContainsString("'author_id'", $form);
        $this->assertStringNotContainsString("TextInput::make('created_at')", $form);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
