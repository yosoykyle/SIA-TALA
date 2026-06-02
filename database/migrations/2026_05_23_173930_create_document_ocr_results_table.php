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
        Schema::create('document_ocr_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_upload_id')->constrained('document_uploads')->cascadeOnDelete();
            $table->string('ocr_engine')->default('google_vision')->index();
            $table->string('parser_version')->nullable();
            $table->longText('ocr_text')->nullable();
            $table->decimal('ocr_confidence', 5, 2)->nullable();
            $table->string('status')->default('ocr_extracted')->index();
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['document_upload_id', 'processed_at'], 'document_ocr_upload_processed_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_ocr_results');
    }
};
