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
        Schema::create('registration_identity_confirmation_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('supersedes_version_id')->nullable();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('admission_application_id');
            $table->string('source_version', 64);
            $table->char('source_hash', 64);
            $table->json('identity_snapshot');
            $table->unsignedBigInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['enrollment_id', 'version'], 'registration_identity_version_unique');
            $table->unique(['enrollment_id', 'source_hash'], 'registration_identity_source_unique');
            $table->foreign('enrollment_id', 'reg_identity_enrollment_fk')->references('id')->on('enrollments')->restrictOnDelete();
            $table->foreign('supersedes_version_id', 'reg_identity_supersedes_fk')->references('id')->on('registration_identity_confirmation_versions')->restrictOnDelete();
            $table->foreign('admission_application_id', 'reg_identity_application_fk')->references('id')->on('applicant_intakes')->restrictOnDelete();
            $table->foreign('confirmed_by', 'reg_identity_confirmer_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_identity_confirmation_versions');
    }
};
