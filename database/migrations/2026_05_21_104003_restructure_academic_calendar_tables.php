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
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year');
            $table->string('education_level')->index(); // shs or college
            $table->date('school_year_start_date');
            $table->date('school_year_end_date');
            $table->string('status')->default('draft')->index(); // draft, active, closed, archived
            $table->string('reference_note')->nullable();
            $table->timestamps();

            $table->unique(['academic_year', 'education_level'], 'academic_year_level_unique');
            $table->index(['education_level', 'status']);
        });

        Schema::table('terms', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dropIndex('terms_start_date_end_date_index');
            $table->renameColumn('name', 'term_name');
            $table->renameColumn('start_date', 'term_start_date');
            $table->renameColumn('end_date', 'term_end_date');

            $table->string('term_type')->nullable()->after('term_name'); // quarter, semester, summer
            $table->date('class_start_date')->nullable();
            $table->date('class_end_date')->nullable();
            $table->timestamp('enrollment_starts_at')->nullable();
            $table->timestamp('enrollment_ends_at')->nullable();
            $table->timestamp('late_enrollment_ends_at')->nullable();
            $table->timestamp('payment_deadline')->nullable();
            $table->timestamp('adjustment_ends_at')->nullable();

            $table->index(['term_start_date', 'term_end_date'], 'terms_term_date_index');
            $table->index(['class_start_date', 'class_end_date'], 'terms_class_date_index');
            $table->index(['academic_year_id', 'term_type'], 'terms_year_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropIndex('terms_year_type_index');
            $table->dropIndex('terms_class_date_index');
            $table->dropIndex('terms_term_date_index');
            $table->dropForeign(['academic_year_id']);

            $table->dropColumn([
                'term_type',
                'class_start_date',
                'class_end_date',
                'enrollment_starts_at',
                'enrollment_ends_at',
                'late_enrollment_ends_at',
                'payment_deadline',
                'adjustment_ends_at',
                'academic_year_id',
            ]);

            $table->renameColumn('term_name', 'name');
            $table->renameColumn('term_start_date', 'start_date');
            $table->renameColumn('term_end_date', 'end_date');
            $table->index(['start_date', 'end_date'], 'terms_start_date_end_date_index');
        });

        Schema::dropIfExists('academic_years');
    }
};
