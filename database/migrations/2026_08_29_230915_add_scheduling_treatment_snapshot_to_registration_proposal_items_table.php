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
        Schema::table('registration_proposal_items', function (Blueprint $table) {
            $table->string('scheduling_treatment_snapshot', 32)->nullable()->after('course_title_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registration_proposal_items', function (Blueprint $table) {
            $table->dropColumn('scheduling_treatment_snapshot');
        });
    }
};
