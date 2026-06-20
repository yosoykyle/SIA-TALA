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
        Schema::create('retention_document_undertakings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_document_requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->index();
            $table->unsignedSmallInteger('extension_count')->default(0);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_document_upload_id')->nullable()->constrained('document_uploads')->nullOnDelete();
            $table->timestamp('overdue_marked_at')->nullable();
            $table->timestamp('hold_applied_at')->nullable();
            $table->string('hold_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('applicant_document_requirement_id', 'retention_document_undertakings_requirement_unique');
            $table->index(['applicant_intake_id', 'status'], 'retention_document_undertakings_intake_status_index');
            $table->index(['student_profile_id', 'status'], 'retention_document_undertakings_profile_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retention_document_undertakings');
    }
};
