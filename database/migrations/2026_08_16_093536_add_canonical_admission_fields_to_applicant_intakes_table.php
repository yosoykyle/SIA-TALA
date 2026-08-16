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
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->foreignId('admission_cycle_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('application_reference', 40)->nullable()->after('admission_cycle_id');
            $table->string('application_state', 24)->nullable()->after('application_reference');
            $table->string('application_path', 24)->nullable()->after('application_state');
            $table->char('citizenship_country_code', 2)->nullable()->after('birth_date');
            $table->string('current_city_municipality', 120)->nullable()->after('phone');
            $table->string('current_province', 120)->nullable()->after('current_city_municipality');
            $table->string('prior_school_name', 160)->nullable()->after('prior_school');
            $table->char('prior_school_country_code', 2)->nullable()->after('prior_school_name');
            $table->unsignedSmallInteger('prior_school_completion_year')->nullable()->after('prior_school_country_code');
            $table->string('lrn', 12)->nullable()->after('prior_school_completion_year');
            $table->string('prior_college_identifier', 64)->nullable()->after('lrn');
            $table->string('guardian_full_name', 160)->nullable()->after('guardian_address');
            $table->string('guardian_relationship', 60)->nullable()->after('guardian_full_name');
            $table->string('guardian_mobile', 32)->nullable()->after('guardian_relationship');
            $table->string('privacy_notice_reference', 255)->nullable()->after('draft_document_references');
            $table->dateTime('privacy_acknowledged_at')->nullable()->after('privacy_notice_reference');
            $table->dateTime('accuracy_declared_at')->nullable()->after('privacy_acknowledged_at');

            $table->unique('application_reference', 'admission_applications_reference_unique');
            $table->unique(
                ['user_id', 'admission_cycle_id'],
                'admission_applications_user_cycle_unique',
            );
            $table->index(
                ['admission_cycle_id', 'application_state', 'program_id', 'updated_at'],
                'admission_applications_workbench_index',
            );
            $table->index(['lrn', 'application_state'], 'admission_applications_lrn_state_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dropUnique('admission_applications_reference_unique');
            $table->dropUnique('admission_applications_user_cycle_unique');
            $table->dropIndex('admission_applications_workbench_index');
            $table->dropIndex('admission_applications_lrn_state_index');
            $table->dropForeign(['admission_cycle_id']);
            $table->dropColumn([
                'admission_cycle_id',
                'application_reference',
                'application_state',
                'application_path',
                'citizenship_country_code',
                'current_city_municipality',
                'current_province',
                'prior_school_name',
                'prior_school_country_code',
                'prior_school_completion_year',
                'lrn',
                'prior_college_identifier',
                'guardian_full_name',
                'guardian_relationship',
                'guardian_mobile',
                'privacy_notice_reference',
                'privacy_acknowledged_at',
                'accuracy_declared_at',
            ]);
        });
    }
};
