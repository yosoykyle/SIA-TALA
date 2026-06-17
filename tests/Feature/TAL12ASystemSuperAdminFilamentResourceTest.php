<?php

namespace Tests\Feature;

use App\Models\FaqEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\FaqEntryPolicy;
use App\Policies\RolePolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\UserPolicy;
use App\Support\ActivityPropertiesFormatter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL12ASystemSuperAdminFilamentResourceTest extends TestCase
{
    public function test_user_management_form_uses_staff_account_fields_and_hides_system_managed_fields(): void
    {
        $source = $this->source('Users/Schemas/UserForm.php');

        foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'username', 'email', 'password', 'status', 'roles'] as $field) {
            $this->assertStringContainsString("'{$field}'", $source);
        }

        foreach (['email_verified_at', 'archived_at', 'archived_reason', 'remember_token'] as $systemManagedField) {
            $this->assertStringNotContainsString("TextInput::make('{$systemManagedField}')", $source);
            $this->assertStringNotContainsString("DateTimePicker::make('{$systemManagedField}')", $source);
        }

        $this->assertStringContainsString('System Super Admin creates staff accounts only', $source);
        $this->assertStringContainsString("ToggleButtons::make('status')", $source);
        $this->assertStringContainsString('User::staffEditableStatusOptions()', $source);
        $this->assertStringContainsString('->in(User::staffEditableStatusValues())', $source);
        $this->assertStringContainsString('User::staffRoleNames()', $source);
        $this->assertStringNotContainsString("'archived' => 'Archived'", $source);
        $this->assertSame([
            User::StatusActive => 'Active',
            User::StatusInactive => 'Inactive',
        ], User::staffEditableStatusOptions());
        $this->assertSame([
            'registrar' => 'Registrar',
            'accounting' => 'Accounting',
            'faculty' => 'Faculty',
            'academic-head' => 'Academic Head',
            'system-super-admin' => 'System Super Admin',
        ], User::staffRoleOptions());
    }

    public function test_system_settings_are_internal_runtime_registry_not_generic_admin_crud(): void
    {
        $resource = $this->source('SystemSettings/SystemSettingResource.php');
        $table = $this->source('SystemSettings/Tables/SystemSettingsTable.php');
        $policy = file_get_contents(app_path('Policies/SystemSettingPolicy.php'));
        $model = file_get_contents(app_path('Models/SystemSetting.php'));

        $this->assertFileDoesNotExist(app_path('Filament/Resources/SystemSettings/Schemas/SystemSettingForm.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/SystemSettings/Pages/EditSystemSetting.php'));
        $this->assertIsString($policy);
        $this->assertIsString($model);

        $this->assertStringContainsString('protected static bool $shouldRegisterNavigation = false;', $resource);
        $this->assertStringContainsString('return false;', $resource);
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString('EditSystemSetting', $resource);
        $this->assertStringNotContainsString('SystemSettingForm', $resource);
        $this->assertStringNotContainsString('EditAction::make()', $table);
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

    public function test_role_permissions_are_read_only_seeded_matrix_not_generic_multi_select_editor(): void
    {
        $resource = $this->source('Roles/RoleResource.php');
        $listPage = $this->source('Roles/Pages/ListRoles.php');
        $table = $this->source('Roles/Tables/RolesTable.php');
        $policy = app(RolePolicy::class);
        $admin = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'manage-users';
            }
        };

        $this->assertFileDoesNotExist(app_path('Filament/Resources/Roles/Schemas/RoleForm.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/Roles/Pages/CreateRole.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/Roles/Pages/EditRole.php'));
        $this->assertStringContainsString("'System Administration'", $resource);
        $this->assertStringContainsString('public static function canCreate(): bool', $resource);
        $this->assertStringContainsString('return false;', $resource);
        $this->assertStringNotContainsString("CreateRole::route('/create')", $resource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringContainsString("TextColumn::make('permissions.name')", $table);
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString("Select::make('permissions')", $resource);
        $this->assertFalse($policy->update($admin, new Role(['name' => 'registrar'])));
    }

    public function test_audit_log_details_present_metadata_as_readable_read_only_lines(): void
    {
        $infolist = $this->source('Activities/Schemas/ActivityInfolist.php');

        $this->assertStringContainsString("TextEntry::make('properties')", $infolist);
        $this->assertStringContainsString('ActivityPropertiesFormatter::lines($record->properties)', $infolist);
        $this->assertStringContainsString('->listWithLineBreaks()', $infolist);
        $this->assertStringContainsString('->bulleted()', $infolist);
        $this->assertStringNotContainsString('KeyValueEntry::make', $infolist);

        $this->assertSame([
            'Status After: archived',
            'Reason: Registrar resigned from service.',
            'Flags > Hard Copy Received: Yes',
            'Approvals > #1 > User ID: 7',
        ], ActivityPropertiesFormatter::lines(collect([
            'status_after' => 'archived',
            'reason' => 'Registrar resigned from service.',
            'flags' => [
                'hard_copy_received' => true,
            ],
            'approvals' => [
                [
                    'user_id' => 7,
                ],
            ],
        ])));
    }

    public function test_staff_user_direct_edit_policy_blocks_self_and_archived_records(): void
    {
        $policy = app(UserPolicy::class);
        $usersTable = $this->source('Users/Tables/UsersTable.php');
        $admin = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'manage-users';
            }
        };
        $ordinaryStaff = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return false;
            }
        };

        $admin->forceFill(['id' => 1, 'status' => 'active']);
        $ordinaryStaff->forceFill(['id' => 3, 'status' => 'active']);
        $otherActiveStaff = (new User)->forceFill(['id' => 2, 'status' => 'active']);
        $archivedStaff = (new User)->forceFill(['id' => 4, 'status' => 'archived']);

        $this->assertStringContainsString("auth()->user()?->can('update', \$record)", $usersTable);
        $this->assertStringContainsString("can('archiveStaffAccount'", $usersTable);
        $this->assertStringContainsString("can('restoreStaffAccount'", $usersTable);
        $this->assertStringContainsString('UserAccountLifecycleService', $usersTable);
        $this->assertStringContainsString('User::StatusArchived', $usersTable);
        $this->assertStringContainsString('User::staffRoleOptions()', $usersTable);
        $this->assertStringNotContainsString('DB::transaction', $usersTable);
        $this->assertStringNotContainsString('forceFill', $usersTable);
        $this->assertStringNotContainsString('syncRoles', $usersTable);
        $this->assertTrue($policy->update($admin, $otherActiveStaff));
        $this->assertTrue($policy->archiveStaffAccount($admin, $otherActiveStaff));
        $this->assertTrue($policy->restoreStaffAccount($admin, $archivedStaff));
        $this->assertFalse($policy->update($admin, $admin));
        $this->assertFalse($policy->archiveStaffAccount($admin, $admin));
        $this->assertFalse($policy->update($admin, $archivedStaff));
        $this->assertFalse($policy->update($ordinaryStaff, $otherActiveStaff));
        $this->assertFalse($policy->restoreStaffAccount($ordinaryStaff, $archivedStaff));
    }

    public function test_faq_entries_are_maintainable_through_permission_gated_admin_crud(): void
    {
        $model = file_get_contents(app_path('Models/FaqEntry.php'));
        $resource = $this->source('FaqEntries/FaqEntryResource.php');
        $form = $this->source('FaqEntries/Schemas/FaqEntryForm.php');
        $table = $this->source('FaqEntries/Tables/FaqEntriesTable.php');
        $listPage = $this->source('FaqEntries/Pages/ListFaqEntries.php');
        $editPage = $this->source('FaqEntries/Pages/EditFaqEntry.php');
        $policy = app(FaqEntryPolicy::class);
        $manager = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'manage-faqs';
            }
        };
        $ordinaryStaff = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return false;
            }
        };

        $this->assertIsString($model);
        $this->assertStringContainsString("'System Administration'", $resource);
        $this->assertStringContainsString("CreateFaqEntry::route('/create')", $resource);
        $this->assertStringContainsString("EditFaqEntry::route('/{record}/edit')", $resource);
        $this->assertStringContainsString('CreateAction::make()', $listPage);
        $this->assertStringContainsString('EditAction::make()', $table);
        $this->assertStringContainsString('DeleteAction::make()', $editPage);

        foreach (['question', 'answer', 'category', 'sort_order', 'is_published'] as $field) {
            $this->assertStringContainsString("'{$field}'", $form);
        }

        foreach (['General', 'Admission / Enrollment', 'Payments / Fees', 'Documents / Requests', 'Grades / Academics', 'Account / Login', 'Technical Support'] as $category) {
            $this->assertStringContainsString($category, $model);
        }

        $this->assertStringContainsString('created_by', $model);
        $this->assertStringContainsString('updated_by', $model);
        $this->assertTrue($policy->viewAny($manager));
        $this->assertTrue($policy->create($manager));
        $this->assertTrue($policy->update($manager, new FaqEntry));
        $this->assertTrue($policy->delete($manager, new FaqEntry));
        $this->assertFalse($policy->viewAny($ordinaryStaff));
        $this->assertFalse($policy->create($ordinaryStaff));
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
