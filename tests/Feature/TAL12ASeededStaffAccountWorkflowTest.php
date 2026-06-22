<?php

namespace Tests\Feature;

use Tests\TestCase;

class TAL12ASeededStaffAccountWorkflowTest extends TestCase
{
    public function test_database_seeder_creates_expected_staff_roles_and_staff_accounts(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);

        foreach (['system-super-admin', 'registrar', 'accounting', 'faculty', 'academic-head'] as $role) {
            $this->assertStringContainsString("'{$role}'", $source);
        }

        foreach (['admin@tala.edu', 'registrar@tala.edu', 'accounting@tala.edu', 'faculty@tala.edu', 'academichead@tala.edu'] as $email) {
            $this->assertStringContainsString($email, $source);
        }

        $this->assertStringContainsString('staffNamePayload', $source);
    }

    public function test_seeded_staff_roles_map_to_admin_panel_permissions(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);

        foreach ([
            'manage-users',
            'manage-faqs',
            'manage-settings',
            'view-audit-logs',
            'approve-documents',
            'create-assessments',
            'process-payments',
            'view-class-list',
            'encode-grades',
            'finalize-grades',
            'authorize-overrides',
        ] as $permission) {
            $this->assertStringContainsString("'{$permission}'", $source);
        }
    }

    public function test_two_factor_migration_remains_while_passkeys_are_removed_from_scope(): void
    {
        $this->assertFileExists(database_path('migrations/2026_05_21_222158_add_two_factor_columns_to_users_table.php'));
        $this->assertFileDoesNotExist(database_path('migrations/2026_05_21_222159_create_passkeys_table.php'));

        $fortifyConfig = file_get_contents(config_path('fortify.php'));

        $this->assertIsString($fortifyConfig);
        $this->assertStringNotContainsString('Features::twoFactorAuthentication', $fortifyConfig);
        $this->assertStringNotContainsString('Features::passkeys', $fortifyConfig);
        $this->assertStringNotContainsString("'passkeys'", $fortifyConfig);
    }
}
