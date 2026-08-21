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
        Schema::create('fee_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_fee_plan_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('state', 24)->default('Draft');
            $table->char('currency', 3)->default('PHP');
            $table->string('authority_reference')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'term_id', 'version']);
            $table->unique('content_hash');
            $table->index(['program_id', 'term_id', 'state'], 'fee_plan_authority_index');
            $table->foreign('supersedes_fee_plan_id', 'fee_plan_supersedes_fk')
                ->references('id')->on('fee_plans')->restrictOnDelete();
        });

        Schema::create('fee_plan_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('code', 40);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['fee_plan_id', 'code']);
            $table->unique(['fee_plan_id', 'sequence']);
        });

        Schema::create('fee_plan_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_plan_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->boolean('required_for_enrollment')->default(true);
            $table->timestamps();

            $table->unique(['fee_plan_id', 'code']);
        });

        Schema::create('term_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('credential_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('state', 24)->default('Open');
            $table->timestamps();

            $table->unique('enrollment_id');
            $table->unique(['credential_user_id', 'term_id']);
            $table->index(['term_id', 'state', 'updated_at']);
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('term_account_id')->nullable()->after('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_plan_id')->nullable()->after('term_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_proposal_version_id')->nullable()->after('fee_plan_id');
            $table->string('assessment_basis', 40)->nullable()->after('source_proposal_version_id');
            $table->string('authority_reference')->nullable()->after('assessment_basis');
            $table->char('content_hash', 64)->nullable()->after('authority_reference');
            $table->unique('content_hash');
            $table->index(['term_account_id', 'state', 'version'], 'term_account_assessment_index');
            $table->foreign('source_proposal_version_id', 'assessment_source_proposal_fk')
                ->references('id')->on('registration_proposal_versions')->restrictOnDelete();
        });

        Schema::table('assessment_lines', function (Blueprint $table) {
            $table->foreignId('fee_rule_id')->nullable()->change();
            $table->foreignId('fee_plan_charge_id')->nullable()->after('fee_rule_id')->constrained()->restrictOnDelete();
            $table->string('obligation_code', 40)->nullable()->after('source_line_key');
        });

        Schema::create('assessment_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->boolean('required_for_enrollment')->default(true);
            $table->timestamps();

            $table->unique(['assessment_id', 'code']);
        });

        Schema::create('approved_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_obligation_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('authority_reference');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index(['term_account_id', 'reversed_at']);
        });

        Schema::create('payment_evidence_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_version_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('state', 24)->default('Submitted');
            $table->string('disk', 64)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->decimal('claimed_amount', 12, 2);
            $table->string('payment_reference')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['term_account_id', 'version']);
            $table->unique('checksum');
            $table->foreign('supersedes_version_id', 'payment_evidence_supersedes_fk')
                ->references('id')->on('payment_evidence_versions')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('student_profile_id')->nullable()->change();
            $table->foreignId('term_account_id')->nullable()->after('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_evidence_version_id')->nullable()->after('term_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('reverses_payment_id')->nullable()->after('payment_evidence_version_id');
            $table->text('reversal_reason')->nullable()->after('provider_reference');
            $table->foreign('reverses_payment_id', 'payment_reverses_fk')
                ->references('id')->on('payments')->restrictOnDelete();
            $table->index(['term_account_id', 'evidence_status', 'paid_at'], 'term_account_payment_index');
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('assessment_obligation_id')->nullable()->after('payment_id')->constrained()->restrictOnDelete();
            $table->unique(['payment_id', 'assessment_obligation_id'], 'payment_obligation_allocation_unique');
        });

        Schema::create('cor_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_version_id')->nullable();
            $table->unsignedInteger('version');
            $table->foreignId('registration_proposal_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('published_timetable_version_id')->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->char('content_hash', 64);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['enrollment_id', 'version']);
            $table->unique('content_hash');
            $table->foreign('supersedes_version_id', 'cor_version_supersedes_fk')
                ->references('id')->on('cor_versions')->restrictOnDelete();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreign('current_cor_version_id', 'registration_case_current_cor_fk')
                ->references('id')->on('cor_versions')->restrictOnDelete();
        });

        Schema::create('student_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('enrollment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_cor_version_id')->constrained('cor_versions')->restrictOnDelete();
            $table->string('authority_reference');
            $table->string('financial_effect', 32);
            $table->json('change_snapshot');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        Schema::create('course_drop_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->restrictOnDelete();
            $table->string('authority_reference');
            $table->text('reason');
            $table->string('finance_state', 40)->default('AccountingReviewPending');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_drop_records');
        Schema::dropIfExists('enrollment_adjustments');
        Schema::dropIfExists('student_number_sequences');
        Schema::table('enrollments', fn (Blueprint $table) => $table->dropForeign('registration_case_current_cor_fk'));
        Schema::dropIfExists('cor_versions');
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique('payment_obligation_allocation_unique');
            $table->dropConstrainedForeignId('assessment_obligation_id');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('term_account_payment_index');
            $table->dropForeign('payment_reverses_fk');
            $table->dropConstrainedForeignId('payment_evidence_version_id');
            $table->dropConstrainedForeignId('term_account_id');
            $table->dropColumn(['reverses_payment_id', 'reversal_reason']);
        });
        Schema::dropIfExists('payment_evidence_versions');
        Schema::dropIfExists('approved_coverages');
        Schema::dropIfExists('assessment_obligations');
        Schema::table('assessment_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_plan_charge_id');
            $table->dropColumn('obligation_code');
        });
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropUnique('assessments_content_hash_unique');
            $table->dropIndex('term_account_assessment_index');
            $table->dropForeign('assessment_source_proposal_fk');
            $table->dropConstrainedForeignId('fee_plan_id');
            $table->dropConstrainedForeignId('term_account_id');
            $table->dropColumn(['source_proposal_version_id', 'assessment_basis', 'authority_reference', 'content_hash']);
        });
        Schema::dropIfExists('term_accounts');
        Schema::dropIfExists('fee_plan_obligations');
        Schema::dropIfExists('fee_plan_charges');
        Schema::dropIfExists('fee_plans');
    }
};
