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
        DB::table('admission_cycles')
            ->whereNull('correction_closes_at')
            ->whereNotNull('closes_at')
            ->update(['correction_closes_at' => DB::raw('closes_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only data repair: later authorized boundaries must not be erased.
    }
};
