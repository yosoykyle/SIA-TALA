<?php

use App\Actions\SystemAdministration\DisposalReviewRetirementGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(DisposalReviewRetirementGuard::class)->assertEmpty();

        Schema::table('operational_events', function (Blueprint $table): void {
            $table->index(
                ['event_domain', 'integration', 'occurred_at', 'id'],
                'operational_events_current_evidence_index',
            );
        });

        Schema::dropIfExists('disposal_reviews');
    }

    public function down(): void
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

        Schema::table('operational_events', function (Blueprint $table): void {
            $table->dropIndex('operational_events_current_evidence_index');
        });
    }
};
