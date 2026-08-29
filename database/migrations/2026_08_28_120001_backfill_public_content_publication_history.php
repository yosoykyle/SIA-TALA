<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('faq_entries')->where('is_published', true)->update(['ever_published' => true]);

            DB::table('permissions')->insertOrIgnore([
                'name' => 'manage-public-notices', 'guard_name' => 'web',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $role = DB::table('roles')->where('name', 'system-super-admin')->where('guard_name', 'web')->value('id');
            $permission = DB::table('permissions')->where('name', 'manage-public-notices')->where('guard_name', 'web')->value('id');
            if ($role !== null) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Publication history and fixed-role access are retained; reverse changes require an authorized forward migration.
    }
};
