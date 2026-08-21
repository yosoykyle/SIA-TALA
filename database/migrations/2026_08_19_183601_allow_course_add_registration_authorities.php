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
        Schema::table('registration_proposal_versions', function (Blueprint $table) {
            $table->string('purpose', 24)->default('Initial')->after('state');
        });
        Schema::table('registration_adjustment_finance_confirmations', function (Blueprint $table) {
            $table->unsignedBigInteger('current_course_enrollment_id')->nullable()->change();
        });
        Schema::table('registration_late_authorities', function (Blueprint $table) {
            $table->unsignedBigInteger('before_course_enrollment_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('registration_adjustment_finance_confirmations')->whereNull('current_course_enrollment_id')->exists()
            || DB::table('registration_late_authorities')->whereNull('before_course_enrollment_id')->exists()) {
            throw new RuntimeException('Cannot restore required before-course links after canonical course-add authority has been recorded.');
        }

        Schema::table('registration_adjustment_finance_confirmations', function (Blueprint $table) {
            $table->unsignedBigInteger('current_course_enrollment_id')->nullable(false)->change();
        });
        Schema::table('registration_late_authorities', function (Blueprint $table) {
            $table->unsignedBigInteger('before_course_enrollment_id')->nullable(false)->change();
        });
        Schema::table('registration_proposal_versions', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
