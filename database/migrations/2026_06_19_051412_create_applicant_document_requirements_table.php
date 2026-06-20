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
        Schema::create('applicant_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admission_requirement_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_requirement_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_key');
            $table->string('label');
            $table->string('gate_type')->index();
            $table->json('permitted_evidence_methods');
            $table->string('storage_class');
            $table->string('sensitivity_class');
            $table->string('ocr_policy');
            $table->string('deadline_strategy')->nullable();
            $table->string('evidence_state')->default('pending')->index();
            $table->foreignId('satisfied_by_document_upload_id')->nullable()->constrained('document_uploads')->nullOnDelete();
            $table->string('satisfied_method')->nullable();
            $table->foreignId('satisfied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('satisfied_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['applicant_intake_id', 'item_key'], 'applicant_document_requirements_intake_item_unique');
            $table->index(['applicant_intake_id', 'gate_type', 'evidence_state'], 'applicant_document_requirements_state_index');
        });

        Schema::table('document_uploads', function (Blueprint $table) {
            $table->foreignId('applicant_document_requirement_id')
                ->nullable()
                ->after('applicant_intake_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropForeign(['applicant_document_requirement_id']);
            $table->dropColumn('applicant_document_requirement_id');
        });

        Schema::dropIfExists('applicant_document_requirements');
    }
};
