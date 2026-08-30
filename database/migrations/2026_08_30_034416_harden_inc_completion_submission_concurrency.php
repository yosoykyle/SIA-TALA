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
        Schema::table('inc_completion_submissions', function (Blueprint $table) {
            $table->foreignId('controlling_result_event_id')->nullable()->after('grade_outcome_event_id')
                ->constrained('grade_outcome_events', 'id', 'inc_sub_control_result_fk')->restrictOnDelete();
            $table->foreignId('controlling_deadline_amendment_id')->nullable()->after('controlling_result_event_id')
                ->constrained('inc_deadline_amendments', 'id', 'inc_sub_control_deadline_fk')->restrictOnDelete();
            $table->date('controlling_deadline')->nullable()->after('controlling_deadline_amendment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inc_completion_submissions', function (Blueprint $table) {
            $table->dropForeign('inc_sub_control_deadline_fk');
            $table->dropForeign('inc_sub_control_result_fk');
            $table->dropColumn(['controlling_deadline_amendment_id', 'controlling_result_event_id', 'controlling_deadline']);
        });
    }
};
