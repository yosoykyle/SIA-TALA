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
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('curriculum_id')
                ->nullable()
                ->after('program_id')
                ->constrained('curriculums')
                ->nullOnDelete();
            $table->string('year_level')->nullable()->after('curriculum_id');
            $table->string('curriculum_period')->nullable()->after('year_level');

            $table->index(['term_id', 'program_id', 'year_level'], 'sections_solver_scope_index');
            $table->index(['curriculum_id', 'year_level', 'curriculum_period'], 'sections_curriculum_scope_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex('sections_curriculum_scope_index');
            $table->dropIndex('sections_solver_scope_index');
            $table->dropConstrainedForeignId('curriculum_id');
            $table->dropColumn(['year_level', 'curriculum_period']);
        });
    }
};
