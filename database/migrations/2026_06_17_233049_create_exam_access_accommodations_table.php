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
        Schema::create('exam_access_accommodations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promissory_note_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope')->index();
            $table->string('basis')->index();
            $table->string('status')->default('pending')->index();
            $table->text('request_reason')->nullable();
            $table->string('certifying_office')->nullable();
            $table->string('certification_reference')->nullable();
            $table->date('certified_at')->nullable();
            $table->string('evidence_disk')->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->string('evidence_file_name')->nullable();
            $table->string('evidence_mime_type', 100)->nullable();
            $table->unsignedBigInteger('evidence_file_size')->nullable();
            $table->date('valid_from');
            $table->date('valid_until');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'status', 'valid_from', 'valid_until'], 'exam_accommodation_student_validity_index');
            $table->index(['academic_year_id', 'status'], 'exam_accommodation_year_status_index');
            $table->index(['term_id', 'status'], 'exam_accommodation_term_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_access_accommodations');
    }
};
