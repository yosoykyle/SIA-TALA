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
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('student_section_id')->nullable()->constrained('term_cohorts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('enrollments')->whereNotNull('student_section_id')->exists()) {
            throw new RuntimeException('Clear or preserve recorded student-section assignments before removing this relationship.');
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_section_id');
        });
    }
};
