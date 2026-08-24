<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $staffRoleNames = ['registrar', 'accounting', 'faculty', 'academic-head', 'system-super-admin'];
        $staffUsers = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', 'App\\Models\\User');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', $staffRoleNames)
            ->select('users.*')
            ->distinct()
            ->get();

        foreach ($staffUsers as $user) {
            if (blank($user->first_name) || blank($user->last_name)) {
                throw new RuntimeException("Staff account {$user->id} lacks canonical name parts; Clinic 1 migration stopped without inventing identity data.");
            }

            DB::table('staff_access_profiles')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'staff_identifier' => null,
                    'first_name' => $user->first_name,
                    'middle_name' => $user->middle_name,
                    'last_name' => $user->last_name,
                    'suffix' => null,
                    'created_at' => $user->created_at,
                    'updated_at' => now(),
                ],
            );
        }

        $archivedUsers = DB::table('users')->where('status', 'archived')->get();

        foreach ($archivedUsers as $user) {
            $roleCount = DB::table('model_has_roles')
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $user->id)
                ->count();

            if ($roleCount === 0) {
                throw new RuntimeException("Archived account {$user->id} has no attributable role history; Clinic 1 migration stopped without inventing access.");
            }

            DB::table('users')->where('id', $user->id)->update([
                'status' => 'disabled',
                'disabled_at' => $user->archived_at ?? $user->updated_at,
                'disabled_reason' => 'Migrated from the attributable legacy archive record.',
                'disabled_authority' => 'Legacy archive record',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('status', 'disabled')
            ->where('disabled_authority', 'Legacy archive record')
            ->update([
                'status' => 'archived',
                'archived_at' => DB::raw('disabled_at'),
                'disabled_at' => null,
                'disabled_reason' => null,
                'disabled_authority' => null,
            ]);

        DB::table('staff_access_profiles')->delete();
    }
};
