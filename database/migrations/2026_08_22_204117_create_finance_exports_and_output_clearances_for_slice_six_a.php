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
        Schema::create('finance_exports', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('type', 40);
            $table->foreignId('term_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->text('purpose');
            $table->json('normalized_scope');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('outcome', 24);
            $table->string('disk', 64)->nullable();
            $table->string('path')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['initiated_by', 'type', 'created_at']);
        });
        Schema::create('official_output_payment_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_account_id')->constrained()->restrictOnDelete();
            $table->string('output_request_reference');
            $table->unsignedInteger('version');
            $table->foreignId('supersedes_clearance_id')->nullable();
            $table->string('state', 24);
            $table->string('authority_reference')->nullable();
            $table->text('safe_reason')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['output_request_reference', 'version'], 'output_clearance_reference_version_unique');
            $table->foreign('supersedes_clearance_id', 'output_clearance_supersedes_fk')->references('id')->on('official_output_payment_clearances')->restrictOnDelete();
            $table->index(['output_request_reference', 'state', 'decided_at'], 'output_clearance_current_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('official_output_payment_clearances');
        Schema::dropIfExists('finance_exports');
    }
};
