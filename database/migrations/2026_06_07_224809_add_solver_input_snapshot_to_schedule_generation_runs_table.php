<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedule_generation_runs', function (Blueprint $table) {
            $table->json('solver_input_snapshot')->nullable()->after('constraint_summary');
            $table->char('solver_input_hash', 64)->nullable()->after('solver_input_snapshot');
            $table->timestamp('solver_snapshot_captured_at')->nullable()->after('solver_input_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_generation_runs', function (Blueprint $table) {
            $table->dropColumn([
                'solver_input_snapshot',
                'solver_input_hash',
                'solver_snapshot_captured_at',
            ]);
        });
    }
};
