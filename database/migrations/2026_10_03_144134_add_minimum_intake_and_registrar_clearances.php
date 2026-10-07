<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_intakes', function (Blueprint $table): void {
            foreach (['lrn_availability' => 24, 'current_barangay' => 120, 'current_street_address' => 160, 'current_postal_code' => 4, 'prior_school_address' => 160, 'optional_identity_notice_reference' => 255, 'optional_identity_consent_purpose' => 255] as $column => $length) {
                if (! Schema::hasColumn('applicant_intakes', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
            if (! Schema::hasColumn('applicant_intakes', 'optional_identity_consented_at')) {
                $table->dateTime('optional_identity_consented_at')->nullable();
            }
        });
        if (! Schema::hasColumn('admission_decisions', 'application_submission_version_id')) {
            Schema::table('admission_decisions', function (Blueprint $table): void {
                $table->foreignId('application_submission_version_id')->nullable()->constrained('application_submission_versions', indexName: 'admission_decision_submission_fk')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('registrar_enrollment_clearances')) {
            Schema::create('registrar_enrollment_clearances', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('admission_application_id');
                $table->unsignedBigInteger('application_submission_version_id');
                $table->unsignedBigInteger('admission_decision_id');
                $table->char('identity_source_hash', 64);
                $table->string('result', 24);
                $table->text('safe_instruction')->nullable();
                $table->text('reason')->nullable();
                $table->string('authority_reference')->nullable();
                $table->boolean('external_checks_confirmed');
                $table->unsignedBigInteger('recorded_by');
                $table->dateTime('recorded_at');
                $table->unsignedBigInteger('supersedes_clearance_id')->nullable();
                $table->timestamps();
            });
        }

        $foreignKeys = Schema::getForeignKeys('registrar_enrollment_clearances');
        foreach ([
            'admission_application_id' => 'applicant_intakes',
            'application_submission_version_id' => 'application_submission_versions',
            'admission_decision_id' => 'admission_decisions',
            'recorded_by' => 'users',
            'supersedes_clearance_id' => 'registrar_enrollment_clearances',
        ] as $column => $target) {
            if (! collect($foreignKeys)->contains(fn (array $key): bool => $key['columns'] === [$column])) {
                Schema::table('registrar_enrollment_clearances', function (Blueprint $table) use ($column, $target): void {
                    $table->foreign($column, 'reg_clearance_'.$column.'_fk')->references('id')->on($target)->restrictOnDelete();
                });
            }
        }
        $indexes = collect(Schema::getIndexes('registrar_enrollment_clearances'))->pluck('name');
        Schema::table('registrar_enrollment_clearances', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->contains('reg_clearance_successor_unique')) {
                $table->unique('supersedes_clearance_id', 'reg_clearance_successor_unique');
            }
            if (! $indexes->contains('registrar_clearance_application_time')) {
                $table->index(['admission_application_id', 'recorded_at'], 'registrar_clearance_application_time');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrar_enrollment_clearances');
        Schema::table('admission_decisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('application_submission_version_id');
        });
        Schema::table('applicant_intakes', function (Blueprint $table): void {
            $table->dropColumn(['lrn_availability', 'current_barangay', 'current_street_address', 'current_postal_code', 'prior_school_address', 'optional_identity_notice_reference', 'optional_identity_consent_purpose', 'optional_identity_consented_at']);
        });
    }
};
