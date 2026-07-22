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
            $table->string('modality_preference')->nullable()->after('credential_basis');
            $table->string('extension_name')->nullable()->after('last_name');
            $table->string('gender')->nullable()->after('birth_date');
            $table->string('civil_status')->nullable()->after('gender');
            $table->string('birth_place')->nullable()->after('civil_status');
            $table->string('address_barangay')->nullable()->after('phone');
            $table->string('address_street')->nullable()->after('address_barangay');
            $table->string('address_city')->nullable()->after('address_street');
            $table->string('address_district')->nullable()->after('address_city');
            $table->string('address_province')->nullable()->after('address_district');
            $table->string('guardian_name')->nullable()->after('prior_school');
            $table->string('guardian_phone')->nullable()->after('guardian_name');
            $table->text('guardian_address')->nullable()->after('guardian_phone');
            $table->json('draft_document_references')->nullable()->after('identity_evidence_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dropColumn([
                'modality_preference',
                'extension_name',
                'gender',
                'civil_status',
                'birth_place',
                'address_barangay',
                'address_street',
                'address_city',
                'address_district',
                'address_province',
                'guardian_name',
                'guardian_phone',
                'guardian_address',
                'draft_document_references',
            ]);
        });
    }
};
