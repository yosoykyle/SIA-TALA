<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('term_cohorts', 'entry_academic_year_id')) {
            Schema::table('term_cohorts', function (Blueprint $table): void {
                $table->foreignId('entry_academic_year_id')
                    ->nullable()
                    ->after('curriculum_version_id')
                    ->constrained('academic_years')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('term_cohorts')
            || ! Schema::hasTable('terms')
            || ! Schema::hasTable('academic_years')
            || ! Schema::hasTable('curriculum_version_batches')) {
            return;
        }

        $extractStartYear = static function (?string $label, ?string $startsOn): ?int {
            $trimmed = trim((string) $label);

            if (preg_match('/((?:19|20)\d{2})/', $trimmed, $matches) === 1) {
                return (int) $matches[1];
            }

            if ($startsOn !== null && preg_match('/^((?:19|20)\d{2})/', $startsOn, $matches) === 1) {
                return (int) $matches[1];
            }

            return null;
        };

        $cohorts = DB::table('term_cohorts')
            ->join('terms', 'terms.id', '=', 'term_cohorts.term_id')
            ->leftJoin('academic_years as term_ay', 'term_ay.id', '=', 'terms.academic_year_id')
            ->whereNull('term_cohorts.entry_academic_year_id')
            ->whereNotNull('term_cohorts.year_level')
            ->get([
                'term_cohorts.id',
                'term_cohorts.curriculum_version_id',
                'term_cohorts.year_level',
                'term_ay.label as term_ay_label',
                'term_ay.starts_on as term_ay_starts_on',
                'terms.starts_on as term_starts_on',
            ]);

        if ($cohorts->isEmpty()) {
            return;
        }

        $curriculumIds = $cohorts
            ->pluck('curriculum_version_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $batchRows = DB::table('curriculum_version_batches')
            ->join('academic_years', 'academic_years.id', '=', 'curriculum_version_batches.academic_year_id')
            ->whereIn('curriculum_version_batches.curriculum_version_id', $curriculumIds)
            ->orderBy('academic_years.starts_on')
            ->orderBy('academic_years.id')
            ->get([
                'curriculum_version_batches.curriculum_version_id',
                'curriculum_version_batches.academic_year_id',
                'academic_years.label',
                'academic_years.starts_on',
            ]);

        $batchesByCurriculum = [];

        foreach ($batchRows as $batchRow) {
            $startYear = $extractStartYear($batchRow->label, $batchRow->starts_on);

            if ($startYear === null) {
                continue;
            }

            $batchesByCurriculum[(int) $batchRow->curriculum_version_id][$startYear] ??= (int) $batchRow->academic_year_id;
        }

        foreach ($cohorts as $cohort) {
            $yearLevel = (int) $cohort->year_level;

            if ($yearLevel < 1) {
                continue;
            }

            $termStartYear = $extractStartYear(
                $cohort->term_ay_label,
                $cohort->term_ay_starts_on ?? $cohort->term_starts_on,
            );

            if ($termStartYear === null) {
                continue;
            }

            $targetStartYear = $termStartYear - ($yearLevel - 1);
            $matchedAcademicYearId = $batchesByCurriculum[(int) $cohort->curriculum_version_id][$targetStartYear] ?? null;

            if ($matchedAcademicYearId !== null) {
                DB::table('term_cohorts')
                    ->where('id', $cohort->id)
                    ->update(['entry_academic_year_id' => $matchedAcademicYearId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('term_cohorts', 'entry_academic_year_id')) {
            Schema::table('term_cohorts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('entry_academic_year_id');
            });
        }
    }
};
