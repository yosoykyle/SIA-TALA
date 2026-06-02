<?php

namespace Tests\Feature;

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

    public function test_system_settings_are_edit_only_documented_settings_not_generic_create_delete_crud(): void
    {
        $resource = $this->source('SystemSettings/SystemSettingResource.php');
        $form = $this->source('SystemSettings/Schemas/SystemSettingForm.php');
        $table = $this->source('SystemSettings/Tables/SystemSettingsTable.php');

        $this->assertStringContainsString('manage-settings', file_get_contents(app_path('Policies/SystemSettingPolicy.php')));
        $this->assertStringContainsString('return false;', $resource);
        $this->assertStringContainsString('Documented Setting', $form);
        $this->assertStringContainsString('helperText', $form);
        $this->assertStringContainsString('EditAction', $table);
        $this->assertStringContainsString('toolbarActions([])', $table);
        $this->assertStringNotContainsString('DeleteAction', $table);
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
