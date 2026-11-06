<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('degree_conferrals', function (Blueprint $table): void {
            $table->unsignedBigInteger('graduation_application_id')->nullable()->change();
            $table->date('conferred_on')->nullable()->change();
            $table->string('authority_reference', 255)->nullable()->change();
        });
        Schema::table('transcript_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('degree_conferral_id')->nullable()->change();
            $table->string('signatory_name', 255)->nullable()->change();
            $table->string('signatory_title', 255)->nullable()->change();
            $table->string('seal_input_type', 32)->nullable()->change();
        });
        Schema::table('transcript_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('degree_conferral_id')->nullable()->change();
        });
        Schema::create('official_output_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('output_type', 64);
            $table->text('reason');
            $table->string('source_reference', 255)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();
            $table->index(['student_profile_id', 'output_type', 'resolved_at'], 'official_output_restrictions_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('official_output_restrictions')->exists()
            || DB::table('degree_conferrals')->whereNull('graduation_application_id')->orWhereNull('conferred_on')->orWhereNull('authority_reference')->exists()
            || DB::table('transcript_requests')->whereNull('degree_conferral_id')->orWhereNull('signatory_name')->orWhereNull('signatory_title')->orWhereNull('seal_input_type')->exists()
            || DB::table('transcript_snapshots')->whereNull('degree_conferral_id')->exists()) {
            throw new RuntimeException('Restore a reviewed database backup to reverse this migration; current records contain facts the older schema cannot represent.');
        }
        Schema::dropIfExists('official_output_restrictions');
        Schema::table('degree_conferrals', function (Blueprint $table): void {
            $table->unsignedBigInteger('graduation_application_id')->nullable(false)->change();
            $table->date('conferred_on')->nullable(false)->change();
            $table->string('authority_reference', 255)->nullable(false)->change();
        });
        Schema::table('transcript_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('degree_conferral_id')->nullable(false)->change();
            $table->string('signatory_name', 255)->nullable(false)->change();
            $table->string('signatory_title', 255)->nullable(false)->change();
            $table->string('seal_input_type', 32)->nullable(false)->change();
        });
        Schema::table('transcript_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('degree_conferral_id')->nullable(false)->change();
        });
    }
};
