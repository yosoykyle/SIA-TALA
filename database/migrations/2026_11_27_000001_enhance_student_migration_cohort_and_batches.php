<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_import_batches')) {
            Schema::create('student_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
                $table->foreignId('curriculum_version_id')->constrained('curriculum_versions')->restrictOnDelete();
                $table->foreignId('entry_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->unsignedTinyInteger('completed_through_year_level')->nullable();
                $table->string('completed_through_term_type', 32)->nullable();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('student_count')->default(0);
                $table->string('filename')->nullable();
                $table->timestamp('reverted_at')->nullable()->index();
                $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['program_id', 'curriculum_version_id'], 'student_import_batches_program_cv_idx');
            });
        }

        if (! Schema::hasColumn('student_profiles', 'entry_academic_year_id')) {
            Schema::table('student_profiles', function (Blueprint $table): void {
                $table->unsignedBigInteger('entry_academic_year_id')->nullable()->after('entry_term_id');
                $table->index('entry_academic_year_id', 'student_profiles_entry_ay_idx');
                $table->foreign('entry_academic_year_id', 'student_profiles_entry_ay_fk')
                    ->references('id')
                    ->on('academic_years')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('student_profiles', 'import_batch_id')) {
            Schema::table('student_profiles', function (Blueprint $table): void {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('entry_academic_year_id');
                $table->index('import_batch_id', 'student_profiles_import_batch_idx');
                $table->foreign('import_batch_id', 'student_profiles_import_batch_fk')
                    ->references('id')
                    ->on('student_import_batches')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('prior_academic_credits', 'is_external_transfer')) {
            Schema::table('prior_academic_credits', function (Blueprint $table): void {
                $table->boolean('is_external_transfer')
                    ->default(false)
                    ->after('source_school')
                    ->index('prior_credits_external_transfer_idx');
            });
        }

        if (! Schema::hasColumn('program_shift_credit_entries', 'source_prior_academic_credit_id')) {
            Schema::table('program_shift_credit_entries', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_prior_academic_credit_id')
                    ->nullable()
                    ->after('source_grade_outcome_event_id');
                $table->foreign('source_prior_academic_credit_id', 'shift_credit_prior_credit_fk')
                    ->references('id')
                    ->on('prior_academic_credits')
                    ->nullOnDelete();
            });
        } else {
            Schema::table('program_shift_credit_entries', function (Blueprint $table): void {
                $table->foreign('source_prior_academic_credit_id', 'shift_credit_prior_credit_fk')
                    ->references('id')
                    ->on('prior_academic_credits')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('program_shift_credit_entries', 'source_prior_academic_credit_id')) {
            Schema::table('program_shift_credit_entries', function (Blueprint $table): void {
                $table->dropForeign('shift_credit_prior_credit_fk');
                $table->dropColumn('source_prior_academic_credit_id');
            });
        }

        if (Schema::hasColumn('prior_academic_credits', 'is_external_transfer')) {
            Schema::table('prior_academic_credits', function (Blueprint $table): void {
                $table->dropIndex('prior_credits_external_transfer_idx');
                $table->dropColumn('is_external_transfer');
            });
        }

        if (Schema::hasColumn('student_profiles', 'import_batch_id')) {
            Schema::table('student_profiles', function (Blueprint $table): void {
                $table->dropForeign('student_profiles_import_batch_fk');
                $table->dropIndex('student_profiles_import_batch_idx');
                $table->dropColumn('import_batch_id');
            });
        }

        if (Schema::hasColumn('student_profiles', 'entry_academic_year_id')) {
            Schema::table('student_profiles', function (Blueprint $table): void {
                $table->dropForeign('student_profiles_entry_ay_fk');
                $table->dropIndex('student_profiles_entry_ay_idx');
                $table->dropColumn('entry_academic_year_id');
            });
        }

        Schema::dropIfExists('student_import_batches');
    }
};
