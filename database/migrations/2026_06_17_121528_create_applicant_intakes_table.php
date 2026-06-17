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
        Schema::create('applicant_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lrn', 12)->nullable()->index();
            $table->date('birthdate');
            $table->string('place_of_birth')->nullable();
            $table->string('gender', 40);
            $table->string('civil_status', 40);
            $table->string('mothers_maiden_name')->nullable();
            $table->string('contact_number', 20);
            $table->string('street')->nullable();
            $table->string('barangay')->nullable();
            $table->string('city');
            $table->string('province');
            $table->string('region')->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('father_name')->nullable();
            $table->string('father_occupation')->nullable();
            $table->string('mother_occupation')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_contact_number', 20)->nullable();
            $table->string('guardian_address')->nullable();
            $table->string('education_level')->index();
            $table->string('year_level')->index();
            $table->string('applicant_type')->index();
            $table->string('preferred_modality')->index();
            $table->string('last_school_name')->nullable();
            $table->string('last_school_address')->nullable();
            $table->string('last_school_year')->nullable();
            $table->timestamp('orientation_modality_acknowledged_at')->nullable();
            $table->timestamp('orientation_policy_accepted_at')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('duplicate_check_status')->default('clear')->index();
            $table->json('duplicate_check_payload')->nullable();
            $table->json('required_documents');
            $table->foreignId('registrar_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registrar_reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('action_required_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['education_level', 'year_level']);
            $table->index(['status', 'created_at']);
            $table->index(['term_id', 'program_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicant_intakes');
    }
};
