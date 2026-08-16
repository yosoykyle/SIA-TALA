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
        $applicantRoleId = DB::table('roles')
            ->where('name', 'applicant')
            ->where('guard_name', 'web')
            ->value('id');

        if ($applicantRoleId === null) {
            return;
        }

        $applicantUserIds = DB::table('model_has_roles')
            ->where('role_id', $applicantRoleId)
            ->where('model_type', 'App\\Models\\User')
            ->select('model_id');

        DB::table('users')
            ->whereIn('id', $applicantUserIds)
            ->whereIn('status', [
                'pending',
                'action_required',
                'for_evaluation',
                'approved',
                'withdrawn',
            ])
            ->update([
                'legacy_applicant_account_status' => DB::raw('status'),
                'status' => 'active',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereNotNull('legacy_applicant_account_status')
            ->update([
                'status' => DB::raw('legacy_applicant_account_status'),
            ]);
    }
};
