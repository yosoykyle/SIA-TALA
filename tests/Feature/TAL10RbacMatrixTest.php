<?php

namespace Tests\Feature;

use App\Models\User;
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
            'SystemSettingPolicy.php' => 'manage-settings',
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
        foreach (['Users', 'Roles', 'SystemSettings', 'FaqEntries', 'Activities'] as $resource) {
            $source = file_get_contents(app_path("Filament/Resources/{$resource}/{$this->resourceClass($resource)}.php"));

            $this->assertIsString($source);
            $this->assertStringContainsString("'System Administration'", $source);
        }
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
