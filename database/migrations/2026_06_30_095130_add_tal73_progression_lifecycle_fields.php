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
        Schema::table('student_lifecycle_changes', function (Blueprint $table) {
            $table->foreignId('expected_return_term_id')
                ->nullable()
                ->after('term_id')
                ->constrained('terms')
                ->restrictOnDelete();
            $table->foreignId('target_program_id')
                ->nullable()
                ->after('expected_return_term_id')
                ->constrained('programs')
                ->restrictOnDelete();
            $table->foreignId('target_curriculum_version_id')
                ->nullable()
                ->after('target_program_id')
                ->constrained('curriculum_versions')
                ->restrictOnDelete();
            $table->string('late_exception_authority')->nullable()->after('authority');
            $table->text('late_exception_reason')->nullable()->after('late_exception_authority');
        });

        Schema::table('financial_accommodations', function (Blueprint $table) {
            $table->boolean('allows_next_term_enrollment')->default(false)->after('allows_finance_gate');
            $table->boolean('allows_reactivation')->default(false)->after('allows_next_term_enrollment');
            $table->boolean('allows_record_release')->default(false)->after('allows_reactivation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_lifecycle_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expected_return_term_id');
            $table->dropConstrainedForeignId('target_program_id');
            $table->dropConstrainedForeignId('target_curriculum_version_id');
            $table->dropColumn(['late_exception_authority', 'late_exception_reason']);
        });

        Schema::table('financial_accommodations', function (Blueprint $table) {
            $table->dropColumn([
                'allows_next_term_enrollment',
                'allows_reactivation',
                'allows_record_release',
            ]);
        });
    }
};
