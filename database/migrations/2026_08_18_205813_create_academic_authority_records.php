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
        Schema::create('program_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('authority_type', 64);
            $table->string('authority_reference', 255);
            $table->string('regulator', 128);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('curriculum_source_reference', 255)->nullable();
            $table->string('state', 32)->default('Draft');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['program_id', 'authority_reference'], 'program_authority_reference_unique');
            $table->index(['program_id', 'state', 'effective_from'], 'program_authority_current_index');
        });

        Schema::create('external_competency_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('requirement_code', 64);
            $table->string('qualification_label', 255);
            $table->string('qualification_level', 128);
            $table->string('treatment', 32);
            $table->string('authority_reference', 255);
            $table->date('authority_date');
            $table->string('state', 32)->default('Draft');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['curriculum_version_id', 'requirement_code'], 'curriculum_external_requirement_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_competency_requirements');
        Schema::dropIfExists('program_authorities');
    }
};
