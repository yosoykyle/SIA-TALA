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
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique('academic_year_level_unique');
            $table->dropIndex(['education_level']);
            $table->dropIndex(['education_level', 'status']);
            $table->dropColumn('education_level');
            $table->unique('academic_year', 'academic_years_academic_year_unique');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropIndex(['education_level']);
            $table->dropIndex(['education_level', 'year_level']);
            $table->dropColumn('education_level');
        });

        Schema::table('fee_templates', function (Blueprint $table) {
            $table->dropUnique('fee_templates_scope_unique');
            $table->dropIndex(['education_level']);
            $table->dropIndex(['education_level', 'is_active']);
            $table->dropColumn('education_level');
            $table->unique(['name', 'program_id', 'year_level'], 'fee_templates_scope_unique');
        });

        Schema::table('installment_policies', function (Blueprint $table) {
            $table->dropUnique('installment_policies_scope_unique');
            $table->dropIndex(['education_level']);
            $table->dropIndex(['education_level', 'is_active']);
            $table->dropColumn('education_level');
            $table->unique(['name', 'program_id', 'year_level'], 'installment_policies_scope_unique');
        });

        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dropIndex(['education_level']);
            $table->dropIndex(['education_level', 'year_level']);
            $table->dropColumn('education_level');
        });

        Schema::table('admission_offerings', function (Blueprint $table) {
            $table->dropIndex('admission_offerings_resolution_index');
            $table->dropIndex(['education_level']);
            $table->dropColumn('education_level');
            $table->index(['term_id', 'entry_route', 'status'], 'admission_offerings_resolution_index');
        });

        Schema::table('admission_capacity_plans', function (Blueprint $table) {
            $table->dropUnique('admission_capacity_plans_scope_unique');
            $table->dropIndex(['education_level']);
            $table->dropColumn('education_level');
            $table->unique(
                ['term_id', 'scope_type', 'program_id', 'year_level', 'delivery_setup', 'status'],
                'admission_capacity_plans_scope_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admission_capacity_plans', function (Blueprint $table) {
            $table->dropUnique('admission_capacity_plans_scope_unique');
            $table->string('education_level')->nullable()->default('college')->index();
            $table->unique(
                ['term_id', 'scope_type', 'education_level', 'program_id', 'year_level', 'delivery_setup', 'status'],
                'admission_capacity_plans_scope_unique',
            );
        });

        Schema::table('admission_offerings', function (Blueprint $table) {
            $table->dropIndex('admission_offerings_resolution_index');
            $table->string('education_level')->default('college')->index();
            $table->index(['term_id', 'education_level', 'entry_route', 'status'], 'admission_offerings_resolution_index');
        });

        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->string('education_level')->default('college')->index();
            $table->index(['education_level', 'year_level']);
        });

        Schema::table('installment_policies', function (Blueprint $table) {
            $table->dropUnique('installment_policies_scope_unique');
            $table->string('education_level')->default('college')->index();
            $table->index(['education_level', 'is_active']);
            $table->unique(['name', 'education_level', 'program_id', 'year_level'], 'installment_policies_scope_unique');
        });

        Schema::table('fee_templates', function (Blueprint $table) {
            $table->dropUnique('fee_templates_scope_unique');
            $table->string('education_level')->default('college')->index();
            $table->index(['education_level', 'is_active']);
            $table->unique(['name', 'education_level', 'program_id', 'year_level'], 'fee_templates_scope_unique');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('education_level')->default('college')->index();
            $table->index(['education_level', 'year_level']);
        });

        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique('academic_years_academic_year_unique');
            $table->string('education_level')->default('college')->index();
            $table->unique(['academic_year', 'education_level'], 'academic_year_level_unique');
            $table->index(['education_level', 'status']);
        });
    }
};
