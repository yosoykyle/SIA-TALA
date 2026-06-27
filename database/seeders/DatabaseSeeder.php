<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            'applicant',
            'student',
            'registrar',
            'accounting',
            'faculty',
            'academic-head',
            'system-super-admin',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }

        $obsoletePermissions = [
            'manage-lis',
            'start-enrollment',
            'upload-enrollment-documents',
            'view-advising-status',
        ];

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $obsoletePermissions)
            ->delete();

        $permissions = [
            'view-grades',
            'view-schedule',
            'view-cor',
            'upload-payment-proof',
            'request-grade-corrections',
            'approve-documents',
            'manage-admission-setup',
            'manage-grade-corrections',
            'manage-curricula',
            'manage-terms',
            'manage-schedules',
            'manage-sections',
            'evaluate-transferees',
            'manage-cor-verifications',
            'review-lock-faculty-availability',
            'manage-faculty-subject-eligibilities',
            'generate-schedule-drafts',
            'commit-schedules',
            'export-schedules',
            'process-payments',
            'create-assessments',
            'post-accounting-adjustments',
            'encode-grades',
            'finalize-grades',
            'verify-grade-submissions',
            'view-class-list',
            'submit-faculty-availability',
            'view-global-records',
            'authorize-overrides',
            'view-grade-submission-progress',
            'view-faculty-availability',
            'manage-users',
            'manage-faqs',
            'manage-settings',
            'view-audit-logs',
            'manage-system-health',
            'manage-cor-templates',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        Role::findByName('applicant')->syncPermissions([]);

        Role::findByName('student')->syncPermissions([
            'view-grades',
            'view-schedule',
            'view-cor',
            'upload-payment-proof',
            'request-grade-corrections',
        ]);

        Role::findByName('registrar')->syncPermissions([
            'approve-documents',
            'manage-admission-setup',
            'manage-curricula',
            'manage-terms',
            'manage-schedules',
            'manage-sections',
            'evaluate-transferees',
            'manage-cor-verifications',
            'manage-grade-corrections',
            'verify-grade-submissions',
            'review-lock-faculty-availability',
            'manage-faculty-subject-eligibilities',
            'generate-schedule-drafts',
            'commit-schedules',
            'export-schedules',
            'view-cor',
            'view-grades',
        ]);

        Role::findByName('accounting')->syncPermissions([
            'process-payments',
            'create-assessments',
            'post-accounting-adjustments',
            'view-cor',
        ]);

        Role::findByName('faculty')->syncPermissions([
            'encode-grades',
            'finalize-grades',
            'view-class-list',
            'submit-faculty-availability',
            'view-schedule',
        ]);

        Role::findByName('academic-head')->syncPermissions([
            'view-global-records',
            'authorize-overrides',
            'view-grade-submission-progress',
            'view-faculty-availability',
            'manage-faculty-subject-eligibilities',
            'view-cor',
            'view-grades',
        ]);

        Role::findByName('system-super-admin')->syncPermissions([
            'manage-users',
            'manage-faqs',
            'manage-settings',
            'view-audit-logs',
            'manage-system-health',
        ]);

        $systemSuperAdmin = User::firstOrCreate(
            ['email' => 'admin@tala.edu'],
            [
                ...User::staffNamePayload('System Super', null, 'Admin'),
                'username' => 'superadmin',
                'password' => Hash::make('password'),
            ]
        );

        $systemSuperAdmin->forceFill([
            ...User::staffNamePayload('System Super', null, 'Admin'),
            'username' => $systemSuperAdmin->username ?: 'superadmin',
            'status' => 'active',
            'email_verified_at' => $systemSuperAdmin->email_verified_at ?? now(),
        ])->save();

        $systemSuperAdmin->syncRoles(['system-super-admin']);

        $roleUsers = [
            'registrar' => [
                'first_name' => 'Registrar',
                'last_name' => 'User',
                'username' => 'registrar',
                'email' => 'registrar@tala.edu',
            ],
            'accounting' => [
                'first_name' => 'Accounting',
                'last_name' => 'User',
                'username' => 'accounting',
                'email' => 'accounting@tala.edu',
            ],
            'faculty' => [
                'first_name' => 'Faculty',
                'last_name' => 'User',
                'username' => 'faculty',
                'email' => 'faculty@tala.edu',
            ],
            'academic-head' => [
                'first_name' => 'Academic Head',
                'last_name' => 'User',
                'username' => 'academichead',
                'email' => 'academichead@tala.edu',
            ],
            'student' => [
                'first_name' => 'Student',
                'last_name' => 'User',
                'username' => 'student',
                'email' => 'student@tala.edu',
            ],
            'applicant' => [
                'first_name' => 'Applicant',
                'last_name' => 'User',
                'username' => 'applicant',
                'email' => 'applicant@tala.edu',
            ],
        ];

        foreach ($roleUsers as $role => $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    ...User::staffNamePayload($data['first_name'], null, $data['last_name']),
                    'username' => $data['username'],
                    'password' => Hash::make('password'),
                ]
            );

            $user->forceFill([
                ...User::staffNamePayload($data['first_name'], null, $data['last_name']),
                'username' => $user->username ?: $data['username'],
                'status' => 'active',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $user->syncRoles([$role]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_settings')) {
            foreach (SystemSetting::SettingDefinitions as $key => $definition) {
                SystemSetting::query()->firstOrCreate(
                    ['key' => $key],
                    ['value' => $definition['default'] ?? null],
                );
            }
        }
    }
}
