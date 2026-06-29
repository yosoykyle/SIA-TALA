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
        Schema::table('scheduling_demands', function (Blueprint $table) {
            $table->json('source_snapshot')->nullable()->after('fixed_start_time');
            $table->json('readiness_findings')->nullable()->after('source_snapshot');
            $table->foreignId('generated_by')->nullable()->after('validation_state')->constrained('users')->nullOnDelete();
            $table->timestamp('readiness_checked_at')->nullable()->after('generated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduling_demands', function (Blueprint $table) {
            $table->dropForeign(['generated_by']);
            $table->dropColumn([
                'source_snapshot',
                'readiness_findings',
                'generated_by',
                'readiness_checked_at',
            ]);
        });
    }
};
