<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TAL-92E: disposal review ledger.
     *
     * Owning contract: PRD `13_system_admin_reports_audit.md` §13.7.4
     * (Retention Rules, rule 4 "keep disposal audit logs", rule 7 "disposal
     * actions must be permission-controlled", rule 8 "disposal is held when
     * a record is under institutional, legal, audit, or active workflow
     * hold") and §13.7.5. Direction A (confirmed 2026-07-08): this table is
     * an audited manual-review ledger; it never physically deletes or
     * purges any record. `student_profile_id` is a direct FK (not
     * polymorphic) because the only in-scope candidate source for this
     * slice is `StudentProfile` records with `archived_at` set (see
     * exclusions in the TAL-92E handoff packet — Permanent and
     * Archive-After-Review record types are out of scope).
     */
    public function up(): void
    {
        Schema::create('disposal_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->string('retention_category');
            $table->boolean('hold_check_result');
            $table->boolean('legal_audit_attestation');
            $table->string('decision');
            $table->text('reason');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at');

            $table->index(['student_profile_id', 'decision']);
            $table->index(['retention_category', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposal_reviews');
    }
};
