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
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropUnique('course_enrollments_enrollment_id_term_offering_id_unique');
            $table->index(
                ['enrollment_id', 'term_offering_id', 'is_current', 'status'],
                'course_registration_current_source_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $duplicates = DB::table('course_enrollments')
            ->select(['enrollment_id', 'term_offering_id'])
            ->groupBy(['enrollment_id', 'term_offering_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Rollback would erase immutable course-registration successor support.');
        }

        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropIndex('course_registration_current_source_index');
            $table->unique(['enrollment_id', 'term_offering_id']);
        });
    }
};
