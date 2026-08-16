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
        Schema::create('admission_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_application_id');
            $table->string('decision', 24);
            $table->text('reason');
            $table->string('authority_reference', 255);
            $table->text('applicant_explanation');
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at');
            $table->foreignId('supersedes_admission_decision_id')->nullable();
            $table->timestamps();

            $table->foreign('admission_application_id', 'admission_decisions_application_fk')
                ->references('id')->on('applicant_intakes')->restrictOnDelete();
            $table->foreign('supersedes_admission_decision_id', 'admission_decisions_supersedes_fk')
                ->references('id')->on('admission_decisions')->restrictOnDelete();

            $table->unique('supersedes_admission_decision_id', 'admission_decisions_successor_unique');
            $table->index(
                ['admission_application_id', 'decided_at'],
                'admission_decisions_history_index',
            );
        });

        Schema::create('official_credential_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_application_id');
            $table->foreignId('admission_requirement_id');
            $table->string('result', 32);
            $table->string('source_reference', 255)->nullable();
            $table->text('safe_explanation')->nullable();
            $table->string('authority_reference', 255);
            $table->dateTime('exception_expires_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->foreignId('supersedes_official_credential_result_id')->nullable();
            $table->timestamps();

            $table->foreign('admission_application_id', 'official_credential_results_application_fk')
                ->references('id')->on('applicant_intakes')->restrictOnDelete();
            $table->foreign('admission_requirement_id', 'official_credential_results_requirement_fk')
                ->references('id')->on('admission_requirements')->restrictOnDelete();
            $table->foreign(
                'supersedes_official_credential_result_id',
                'official_credential_results_supersedes_fk',
            )->references('id')->on('official_credential_results')->restrictOnDelete();

            $table->unique(
                'supersedes_official_credential_result_id',
                'official_credential_results_successor_unique',
            );
            $table->index(
                ['admission_application_id', 'admission_requirement_id', 'result'],
                'official_credential_results_current_index',
            );
        });

        Schema::create('identity_match_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_application_id');
            $table->string('review_key', 160)->unique();
            $table->string('match_type', 40);
            $table->string('outcome', 32)->default('Pending');
            $table->foreignId('candidate_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('evidence_reference', 255)->nullable();
            $table->string('corrected_identifier', 64)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('admission_application_id', 'identity_match_reviews_application_fk')
                ->references('id')->on('applicant_intakes')->restrictOnDelete();

            $table->index(
                ['admission_application_id', 'outcome'],
                'identity_match_reviews_application_outcome_index',
            );
        });

        Schema::create('admission_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_application_id');
            $table->string('event_type', 40);
            $table->string('event_key', 160)->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source_type', 160)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->foreign('admission_application_id', 'admission_application_events_application_fk')
                ->references('id')->on('applicant_intakes')->restrictOnDelete();

            $table->index(
                ['admission_application_id', 'occurred_at'],
                'admission_application_events_history_index',
            );
            $table->index(['source_type', 'source_id'], 'admission_application_events_source_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_application_events');
        Schema::dropIfExists('identity_match_reviews');
        Schema::dropIfExists('official_credential_results');
        Schema::dropIfExists('admission_decisions');
    }
};
