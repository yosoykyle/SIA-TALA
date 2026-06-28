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
        Schema::create('admission_requirement_policies', function (Blueprint $table) {
            $table->id();
            $table->string('admission_category');
            $table->string('credential_basis');
            $table->string('requirement_type');
            $table->string('evidence_method');
            $table->string('blocking_level');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('state');
            $table->string('authority');
            $table->timestamps();
            $table->unique(['admission_category', 'credential_basis', 'requirement_type', 'effective_from'], 'admission_policy_identity_unique');
            $table->index(['admission_category', 'credential_basis', 'state'], 'admission_policy_applicability_index');
        });

        Schema::create('applicant_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('admission_category');
            $table->string('credential_basis');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('birth_date')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('prior_school')->nullable();
            $table->string('identity_evidence_reference')->nullable();
            $table->string('status');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'term_id', 'program_id']);
            $table->index(['last_name', 'first_name', 'birth_date'], 'applicant_duplicate_match_index');
            $table->index(['user_id', 'term_id', 'status'], 'applicant_active_intake_index');
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('applicant_intake_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('student_number')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('birth_date')->nullable();
            $table->string('prior_identifier')->nullable()->index();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->string('lifecycle_status');
            $table->string('academic_standing');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('merged_into_id')->nullable()->index();
            $table->timestamps();
            $table->index(['program_id', 'lifecycle_status']);
            $table->index(['curriculum_version_id', 'academic_standing'], 'student_curriculum_standing_index');
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->foreignId('applicant_intake_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_policy_id')->constrained('admission_requirement_policies')->restrictOnDelete();
            $table->string('requirement_type');
            $table->string('status');
            $table->string('blocking_level');
            $table->string('evidence_method');
            $table->string('verification_status');
            $table->date('deadline')->nullable()->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('waiver_reason')->nullable();
            $table->text('undertaking_terms')->nullable();
            $table->timestamps();
            $table->unique(['applicant_intake_id', 'source_policy_id'], 'checklist_applicant_policy_unique');
            $table->unique(['student_profile_id', 'source_policy_id'], 'checklist_student_policy_unique');
            $table->index(['owner_type', 'status']);
            $table->index(['status', 'blocking_level']);
        });

        Schema::create('document_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('checksum');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('evidence_method');
            $table->string('status');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('replaces_document_evidence_id')->nullable()->constrained('document_evidence')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['checklist_item_id', 'checksum']);
            $table->index(['checklist_item_id', 'status']);
        });

        Schema::create('duplicate_profile_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duplicate_student_profile_id');
            $table->foreignId('primary_student_profile_id');
            $table->string('resolution_type');
            $table->text('reason');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at');
            $table->unique('duplicate_student_profile_id', 'duplicate_resolution_duplicate_unique');
            $table->index('primary_student_profile_id');
            $table->foreign('duplicate_student_profile_id', 'duplicate_resolution_duplicate_fk')->references('id')->on('student_profiles')->restrictOnDelete();
            $table->foreign('primary_student_profile_id', 'duplicate_resolution_primary_fk')->references('id')->on('student_profiles')->restrictOnDelete();
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('student_profiles')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE checklist_items ADD CONSTRAINT checklist_items_owner_check CHECK ((owner_type = 'APPLICANT' AND applicant_intake_id IS NOT NULL AND student_profile_id IS NULL) OR (owner_type = 'STUDENT' AND student_profile_id IS NOT NULL AND applicant_intake_id IS NULL))");
        DB::unprepared("CREATE TRIGGER student_profiles_prevent_self_merge BEFORE UPDATE ON student_profiles FOR EACH ROW BEGIN IF NEW.merged_into_id = NEW.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A student profile cannot be merged into itself'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duplicate_profile_resolutions');
        Schema::dropIfExists('document_evidence');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('applicant_intakes');
        Schema::dropIfExists('admission_requirement_policies');
    }
};
