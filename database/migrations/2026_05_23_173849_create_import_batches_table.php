<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('import_type', [
                'student_data',
                'legacy_grades',
                'legacy_financial',
                'enrollment_records',
                'curriculum',
            ]);
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->enum('status', ['pending_review', 'committed', 'cancelled'])->default('pending_review');
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->json('error_log')->default(new Expression('(JSON_ARRAY())'));
            $table->timestamps();

            $table->index(['import_type', 'status']);
            $table->index('imported_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
