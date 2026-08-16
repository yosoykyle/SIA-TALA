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
        Schema::create('application_submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_application_id');
            $table->foreignId('admission_requirement_set_id');
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');
            $table->string('privacy_notice_reference', 255);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->foreign('admission_application_id', 'application_submission_versions_application_fk')
                ->references('id')->on('applicant_intakes')->restrictOnDelete();
            $table->foreign('admission_requirement_set_id', 'application_submission_versions_requirement_set_fk')
                ->references('id')->on('admission_requirement_sets')->restrictOnDelete();

            $table->unique(
                ['admission_application_id', 'version'],
                'application_submission_versions_application_version_unique',
            );
            $table->index(
                ['admission_application_id', 'submitted_at'],
                'application_submission_versions_history_index',
            );
        });

        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->foreignId('current_submission_version_id')
                ->nullable()
                ->after('accuracy_declared_at')
                ->constrained('application_submission_versions')
                ->restrictOnDelete();
        });

        Schema::table('document_evidence', function (Blueprint $table) {
            $table->foreignId('checklist_item_id')->nullable()->change();
            $table->foreignId('admission_application_id')->nullable()->after('checklist_item_id');
            $table->foreignId('admission_requirement_id')->nullable()->after('admission_application_id');
            $table->foreignId('application_submission_version_id')->nullable()->after('admission_requirement_id');

            $table->foreign('admission_application_id', 'document_evidence_admission_application_fk')
                ->references('id')->on('applicant_intakes')->restrictOnDelete();
            $table->foreign('admission_requirement_id', 'document_evidence_admission_requirement_fk')
                ->references('id')->on('admission_requirements')->restrictOnDelete();
            $table->foreign('application_submission_version_id', 'document_evidence_submission_version_fk')
                ->references('id')->on('application_submission_versions')->restrictOnDelete();

            $table->unique(
                ['admission_application_id', 'admission_requirement_id', 'checksum'],
                'document_evidence_canonical_checksum_unique',
            );
            $table->index(
                ['admission_application_id', 'admission_requirement_id', 'uploaded_at'],
                'document_evidence_canonical_history_index',
            );
        });

        Schema::create('preliminary_evidence_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_evidence_id')->constrained('document_evidence')->restrictOnDelete();
            $table->string('result', 40);
            $table->text('reason')->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at');
            $table->foreignId('supersedes_preliminary_evidence_review_id')->nullable();
            $table->timestamps();

            $table->foreign(
                'supersedes_preliminary_evidence_review_id',
                'preliminary_evidence_reviews_supersedes_fk',
            )->references('id')->on('preliminary_evidence_reviews')->restrictOnDelete();

            $table->unique(
                'supersedes_preliminary_evidence_review_id',
                'preliminary_evidence_reviews_successor_unique',
            );
            $table->index(
                ['document_evidence_id', 'reviewed_at'],
                'preliminary_evidence_reviews_history_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('document_evidence')->whereNull('checklist_item_id')->exists()) {
            throw new RuntimeException('Canonical evidence exists; use a forward migration instead of destructive rollback.');
        }

        Schema::dropIfExists('preliminary_evidence_reviews');

        Schema::table('document_evidence', function (Blueprint $table) {
            $table->dropUnique('document_evidence_canonical_checksum_unique');
            $table->dropIndex('document_evidence_canonical_history_index');
            $table->dropForeign('document_evidence_admission_application_fk');
            $table->dropForeign('document_evidence_admission_requirement_fk');
            $table->dropForeign('document_evidence_submission_version_fk');
            $table->dropColumn([
                'admission_application_id',
                'admission_requirement_id',
                'application_submission_version_id',
            ]);
            $table->foreignId('checklist_item_id')->nullable(false)->change();
        });

        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dropForeign(['current_submission_version_id']);
            $table->dropColumn('current_submission_version_id');
        });

        Schema::dropIfExists('application_submission_versions');
    }
};
