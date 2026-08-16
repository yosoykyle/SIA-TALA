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
        Schema::create('admission_requirement_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->string('application_path', 24);
            $table->unsignedSmallInteger('version');
            $table->string('state', 20)->default('Draft');
            $table->string('authority_reference', 255);
            $table->dateTime('effective_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('replaces_requirement_set_id')->nullable();
            $table->foreign(
                'replaces_requirement_set_id',
                'admission_requirement_sets_replaces_fk',
            )->references('id')->on('admission_requirement_sets')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['admission_cycle_id', 'application_path', 'version'],
                'admission_requirement_sets_version_unique',
            );
            $table->unique('replaces_requirement_set_id', 'admission_requirement_sets_replacement_unique');
            $table->index(
                ['admission_cycle_id', 'application_path', 'state'],
                'admission_requirement_sets_applicability_index',
            );
        });

        Schema::create('admission_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_requirement_set_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('label', 160);
            $table->string('authority_reference', 255);
            $table->text('purpose');
            $table->boolean('requires_preliminary_evidence')->default(false);
            $table->string('official_submission_method', 24);
            $table->string('due_stage', 32);
            $table->text('applicant_instructions')->nullable();
            $table->text('registrar_instructions')->nullable();
            $table->boolean('exception_permitted')->default(false);
            $table->string('required_approving_authority', 160)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['admission_requirement_set_id', 'code'],
                'admission_requirements_set_code_unique',
            );
            $table->index(
                ['admission_requirement_set_id', 'due_stage', 'display_order'],
                'admission_requirements_stage_order_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_requirements');
        Schema::dropIfExists('admission_requirement_sets');
    }
};
