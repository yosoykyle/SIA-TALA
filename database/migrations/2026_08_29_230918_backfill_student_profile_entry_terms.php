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
        DB::table('enrollments')
            ->whereNotNull('student_profile_id')
            ->whereNotNull('officially_enrolled_at')
            ->select('student_profile_id')
            ->distinct()
            ->orderBy('student_profile_id')
            ->pluck('student_profile_id')
            ->each(function (int $studentProfileId): void {
                $earliestAt = DB::table('enrollments')
                    ->where('student_profile_id', $studentProfileId)
                    ->whereNotNull('officially_enrolled_at')
                    ->min('officially_enrolled_at');

                if ($earliestAt === null) {
                    return;
                }

                $termIds = DB::table('enrollments')
                    ->where('student_profile_id', $studentProfileId)
                    ->where('officially_enrolled_at', $earliestAt)
                    ->pluck('term_id')
                    ->unique();

                if ($termIds->count() !== 1) {
                    return;
                }

                DB::table('student_profiles')
                    ->where('id', $studentProfileId)
                    ->whereNull('entry_term_id')
                    ->update(['entry_term_id' => $termIds->first()]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The schema migration owns removal of the backfilled column.
    }
};
