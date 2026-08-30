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
        $ambiguousLegacyDataExists = DB::table('grade_roster_rows')
            ->where(function ($query): void {
                $query->whereNotNull('prelim_equivalent')
                    ->orWhereNotNull('midterm_equivalent')
                    ->orWhereNotNull('final_equivalent')
                    ->orWhereNotNull('computed_average')
                    ->orWhere('current_outcome_code', 'P');
            })
            ->exists();

        if ($ambiguousLegacyDataExists) {
            throw new RuntimeException(
                'Slice 5 cleanup stopped: legacy period-derived or P-grade data requires an explicit disposition decision.',
            );
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE grade_roster_rows DROP CHECK grade_rows_range_check');
        }

        Schema::table('grade_roster_rows', function (Blueprint $table) {
            $table->dropColumn([
                'prelim_equivalent',
                'midterm_equivalent',
                'final_equivalent',
                'computed_average',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grade_roster_rows', function (Blueprint $table) {
            $table->decimal('prelim_equivalent', 7, 4)->nullable()->after('course_enrollment_id');
            $table->decimal('midterm_equivalent', 7, 4)->nullable()->after('prelim_equivalent');
            $table->decimal('final_equivalent', 7, 4)->nullable()->after('midterm_equivalent');
            $table->decimal('computed_average', 7, 4)->nullable()->after('final_equivalent');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE grade_roster_rows ADD CONSTRAINT grade_rows_range_check CHECK ((prelim_equivalent IS NULL OR prelim_equivalent BETWEEN 0 AND 100) AND (midterm_equivalent IS NULL OR midterm_equivalent BETWEEN 0 AND 100) AND (final_equivalent IS NULL OR final_equivalent BETWEEN 0 AND 100) AND (computed_average IS NULL OR computed_average BETWEEN 0 AND 100))');
        }
    }
};
