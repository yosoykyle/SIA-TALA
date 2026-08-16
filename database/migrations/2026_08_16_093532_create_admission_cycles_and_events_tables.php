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
        Schema::create('admission_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label', 160);
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('state', 20)->default('Draft');
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->text('applicant_instructions')->nullable();
            $table->string('support_contact', 255)->nullable();
            $table->string('privacy_notice_reference', 255)->nullable();
            $table->foreignId('registrar_owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['state', 'opens_at', 'closes_at'], 'admission_cycles_availability_index');
            $table->index(['term_id', 'state'], 'admission_cycles_term_state_index');
        });

        Schema::create('admission_cycle_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->boolean('accepts_first_year')->default(false);
            $table->boolean('accepts_transferee')->default(false);
            $table->timestamps();

            $table->unique(['admission_cycle_id', 'program_id'], 'admission_cycle_program_unique');
        });

        Schema::create('admission_cycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('event_key', 160)->unique();
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('authority_reference', 255);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['admission_cycle_id', 'occurred_at'], 'admission_cycle_events_history_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_cycle_events');
        Schema::dropIfExists('admission_cycle_program');
        Schema::dropIfExists('admission_cycles');
    }
};
