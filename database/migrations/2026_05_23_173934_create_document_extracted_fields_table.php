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
        Schema::create('document_extracted_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_upload_id')->constrained('document_uploads')->cascadeOnDelete();
            $table->foreignId('document_ocr_result_id')->nullable()->constrained('document_ocr_results')->nullOnDelete();
            $table->string('field_name');
            $table->text('extracted_value')->nullable();
            $table->text('student_confirmed_value')->nullable();
            $table->text('approved_value')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('status')->default('extracted')->index(); // extracted, student_confirmed, approved, rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['document_upload_id', 'field_name'], 'document_extracted_field_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_extracted_fields');
    }
};
