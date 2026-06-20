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
        Schema::create('document_requirement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_requirement_policy_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('gate_type')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('permitted_evidence_methods');
            $table->string('storage_class')->default('credential_file');
            $table->string('sensitivity_class')->default('standard');
            $table->string('ocr_policy')->default('optional');
            $table->json('verified_field_mapping')->nullable();
            $table->string('deadline_strategy')->nullable();
            $table->string('retention_policy')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['admission_requirement_policy_id', 'key'], 'document_requirement_items_policy_key_unique');
            $table->index(['admission_requirement_policy_id', 'gate_type'], 'document_requirement_items_gate_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_requirement_items');
    }
};
