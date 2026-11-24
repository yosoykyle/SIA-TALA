<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_version_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['program_id', 'academic_year_id'], 'cv_batches_program_ay_unique');
            $table->unique(['curriculum_version_id', 'academic_year_id'], 'cv_batches_cv_ay_unique');
        });

        Schema::table('curriculum_versions', function (Blueprint $table): void {
            $table->foreignId('copied_from_curriculum_version_id')
                ->nullable()
                ->after('program_id')
                ->constrained('curriculum_versions')
                ->nullOnDelete();
        });

        Schema::table('term_cohorts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('year_level')
                ->nullable()
                ->after('reference');
        });

        $rows = DB::table('curriculum_versions')
            ->join('terms', 'terms.id', '=', 'curriculum_versions.effective_entry_term_id')
            ->whereNotNull('curriculum_versions.effective_entry_term_id')
            ->whereNotNull('terms.academic_year_id')
            ->orderByRaw("CASE WHEN curriculum_versions.state = 'ACTIVE' THEN 0 WHEN curriculum_versions.state = 'SUPERSEDED' THEN 1 ELSE 2 END")
            ->orderBy('curriculum_versions.id')
            ->get([
                'curriculum_versions.id as curriculum_version_id',
                'curriculum_versions.program_id',
                'terms.academic_year_id',
                'curriculum_versions.approved_by',
            ]);

        $now = now();

        foreach ($rows as $row) {
            $exists = DB::table('curriculum_version_batches')
                ->where('program_id', $row->program_id)
                ->where('academic_year_id', $row->academic_year_id)
                ->exists();

            if (! $exists) {
                DB::table('curriculum_version_batches')->insert([
                    'program_id' => $row->program_id,
                    'curriculum_version_id' => $row->curriculum_version_id,
                    'academic_year_id' => $row->academic_year_id,
                    'added_by' => $row->approved_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('term_cohorts', function (Blueprint $table): void {
            $table->dropColumn('year_level');
        });

        Schema::table('curriculum_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('copied_from_curriculum_version_id');
        });

        Schema::dropIfExists('curriculum_version_batches');
    }
};
