<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the fixed authorization vocabulary required by the MVP baseline.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->canonicalRoles() as $role) {
            Role::query()->firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function canonicalRoles(): array
    {
        return [
            'applicant',
            'student',
            'registrar',
            'accounting',
            'faculty',
            'academic-head',
            'system-super-admin',
        ];
    }
}
