<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_profile_events', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->change();
        });
        Schema::table('grade_outcome_events', function (Blueprint $table): void {
            $table->string('authority', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('grade_outcome_events')->whereNull('authority')->exists()) {
            throw new RuntimeException('Grade outcomes without an external authority must remain nullable.');
        }

        if (DB::table('student_profile_events')->whereNull('authority_reference')->exists()) {
            throw new RuntimeException('Student profile events without an external authority reference must remain nullable.');
        }

        Schema::table('grade_outcome_events', function (Blueprint $table): void {
            $table->string('authority', 255)->nullable(false)->change();
        });
        Schema::table('student_profile_events', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable(false)->change();
        });
    }
};
