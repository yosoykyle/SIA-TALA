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
        Schema::create('graduation_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('supersedes_application_id')->nullable();
            $table->string('state', 24);
            $table->string('active_scope_key')->nullable()->unique();
            $table->char('source_fingerprint', 64);
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at');
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->string('correction_authority_reference')->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamps();

            $table->foreign('supersedes_application_id', 'graduation_applications_supersedes_fk')
                ->references('id')->on('graduation_applications')->restrictOnDelete();
            $table->unique(['student_profile_id', 'curriculum_version_id', 'version'], 'graduation_application_version_unique');
            $table->index(['student_profile_id', 'state', 'applied_at'], 'graduation_applications_state_index');
        });

        Schema::create('completion_readiness_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('graduation_application_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('supersedes_readiness_id')->nullable();
            $table->string('state', 40);
            $table->char('source_fingerprint', 64);
            $table->json('source_snapshot');
            $table->json('blockers');
            $table->foreignId('generated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->foreign('supersedes_readiness_id', 'completion_readiness_supersedes_fk')
                ->references('id')->on('completion_readiness_versions')->restrictOnDelete();
            $table->unique(['student_profile_id', 'version'], 'completion_readiness_version_unique');
            $table->index(['student_profile_id', 'state', 'generated_at'], 'completion_readiness_state_index');
        });

        Schema::create('degree_conferrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('graduation_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('completion_readiness_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('supersedes_conferral_id')->nullable();
            $table->string('active_scope_key')->nullable()->unique();
            $table->string('program_name_snapshot');
            $table->string('degree_name');
            $table->date('conferred_on');
            $table->string('authority_reference');
            $table->string('honor_text')->nullable();
            $table->string('honor_authority_reference')->nullable();
            $table->char('source_fingerprint', 64);
            $table->json('final_evaluation_snapshot');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('supersedes_conferral_id', 'degree_conferrals_supersedes_fk')
                ->references('id')->on('degree_conferrals')->restrictOnDelete();
            $table->unique(['student_profile_id', 'version'], 'degree_conferral_version_unique');
            $table->index(['student_profile_id', 'conferred_on'], 'degree_conferrals_student_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('degree_conferrals');
        Schema::dropIfExists('completion_readiness_versions');
        Schema::dropIfExists('graduation_applications');
    }
};
