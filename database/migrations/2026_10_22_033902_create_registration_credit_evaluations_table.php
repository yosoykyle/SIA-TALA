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
        Schema::create('registration_credit_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_submission_version_id')->constrained('application_submission_versions', indexName: 'reg_credit_submission_fk')->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->json('credited_curriculum_entry_ids');
            $table->string('authority_reference');
            $table->date('approved_on');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->index(['enrollment_id', 'id'], 'reg_credit_case_idx');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_credit_evaluations');
    }
};
