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
        Schema::create('application_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_application_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('state', 20)->default('Active');
            $table->text('applicant_instruction');
            $table->string('responsible_party', 120);
            $table->dateTime('due_at');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('supersedes_correction_request_id')->nullable();
            $table->timestamps();

            $table->foreign('admission_application_id', 'application_correction_requests_application_fk')
                ->references('id')->on('applicant_intakes')->restrictOnDelete();
            $table->foreign('supersedes_correction_request_id', 'application_correction_requests_supersedes_fk')
                ->references('id')->on('application_correction_requests')->restrictOnDelete();

            $table->unique(
                ['admission_application_id', 'sequence'],
                'application_correction_requests_sequence_unique',
            );
            $table->unique(
                'supersedes_correction_request_id',
                'application_correction_requests_successor_unique',
            );
            $table->index(
                ['admission_application_id', 'state', 'due_at'],
                'application_correction_requests_work_index',
            );
        });

        Schema::create('application_correction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_correction_request_id');
            $table->string('scope_type', 20);
            $table->string('scope_key', 160);
            $table->foreignId('admission_requirement_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->foreign('application_correction_request_id', 'application_correction_items_request_fk')
                ->references('id')->on('application_correction_requests')->restrictOnDelete();

            $table->unique(
                ['application_correction_request_id', 'scope_type', 'scope_key'],
                'application_correction_items_scope_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_correction_items');
        Schema::dropIfExists('application_correction_requests');
    }
};
