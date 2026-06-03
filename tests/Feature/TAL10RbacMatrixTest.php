<?php

namespace Tests\Feature;

use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL10RbacMatrixTest extends TestCase
{
    public function test_admin_panel_access_is_limited_to_staff_roles_only(): void
    {
        $source = $this->source(User::class);

        foreach (['registrar', 'accounting', 'faculty', 'academic-head', 'system-super-admin'] as $role) {
            $this->assertStringContainsString("'{$role}'", $source);
        }

        $this->assertStringNotContainsString("'student'", $source);
        $this->assertStringNotContainsString("'applicant'", $source);
    }

    public function test_core_role_permissions_are_guarded_by_policies(): void
    {
        $expectations = [
            'UserPolicy.php' => 'manage-users',
            'FaqEntryPolicy.php' => 'manage-faqs',
            'EnrollmentPolicy.php' => 'approve-documents',
            'EnrollmentPolicy.php::assessment' => 'create-assessments',
            'PaymentPolicy.php' => 'process-payments',
            'GradePolicy.php' => 'authorize-overrides',
            'EnrollmentSubjectPolicy.php' => 'encode-grades',
            'DocumentRequestPolicy.php' => 'manage-document-requests',
            'DocumentUploadPolicy.php' => 'approve-documents',
        ];

        foreach ($expectations as $file => $permission) {
            $path = str_contains($file, '::') ? str($file)->before('::')->toString() : $file;
            $source = file_get_contents(app_path("Policies/{$path}"));

            $this->assertIsString($source);
            $this->assertStringContainsString($permission, $source, "{$path} should enforce {$permission}.");
        }
    }

    public function test_system_super_admin_resources_are_separated_from_academic_and_finance_domains(): void
    {
        foreach (['Users', 'Roles', 'FaqEntries', 'Activities'] as $resource) {
            $source = file_get_contents(app_path("Filament/Resources/{$resource}/{$this->resourceClass($resource)}.php"));

            $this->assertIsString($source);
            $this->assertStringContainsString("'System Administration'", $source);
        }

        $systemSettingsResource = file_get_contents(app_path('Filament/Resources/SystemSettings/SystemSettingResource.php'));
        $systemSettingsPolicy = file_get_contents(app_path('Policies/SystemSettingPolicy.php'));

        $this->assertIsString($systemSettingsResource);
        $this->assertIsString($systemSettingsPolicy);
        $this->assertStringContainsString('protected static bool $shouldRegisterNavigation = false;', $systemSettingsResource);
        $this->assertStringNotContainsString('return $user->can(\'manage-settings\')', $systemSettingsPolicy);
    }

    public function test_vendor_backed_system_administration_resources_are_policy_registered(): void
    {
        $this->assertInstanceOf(RolePolicy::class, Gate::getPolicyFor(Role::class));
        $this->assertInstanceOf(ActivityPolicy::class, Gate::getPolicyFor(Activity::class));
    }

    public function test_roles_and_audit_logs_are_guarded_by_system_admin_permissions(): void
    {
        $rolePolicy = Gate::getPolicyFor(Role::class);
        $activityPolicy = Gate::getPolicyFor(Activity::class);

        $this->assertInstanceOf(RolePolicy::class, $rolePolicy);
        $this->assertInstanceOf(ActivityPolicy::class, $activityPolicy);

        $ordinaryStaff = new PermissionProbeUser;
        $systemSuperAdmin = new PermissionProbeUser(['manage-users', 'view-audit-logs']);

        $this->assertFalse($rolePolicy->viewAny($ordinaryStaff));
        $this->assertFalse($activityPolicy->viewAny($ordinaryStaff));
        $this->assertTrue($rolePolicy->viewAny($systemSuperAdmin));
        $this->assertTrue($activityPolicy->viewAny($systemSuperAdmin));
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }

    private function resourceClass(string $resource): string
    {
        return str($resource)->singular()->toString().'Resource';
    }
}

class PermissionProbeUser extends User
{
    /**
     * @param  list<string>  $allowedPermissions
     */
    public function __construct(private array $allowedPermissions = [])
    {
        parent::__construct();
    }

    /**
     * @param  string|iterable<string>  $abilities
     * @param  mixed  $arguments
     */
    public function can($abilities, $arguments = []): bool
    {
        foreach ((array) $abilities as $ability) {
            if (in_array((string) $ability, $this->allowedPermissions, true)) {
                return true;
            }
        }

        return false;
    }
}
