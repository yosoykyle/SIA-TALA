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
            $table->date('birthdate')->nullable()->change();
            $table->string('gender', 40)->nullable()->change();
            $table->string('civil_status', 40)->nullable()->change();
            $table->string('contact_number', 20)->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('province')->nullable()->change();
            $table->string('year_level')->nullable()->change();
            $table->string('applicant_type')->nullable()->change();
            $table->string('preferred_modality')->nullable()->change();
            $table->string('status')->default('draft')->change();
            $table->dropColumn('required_documents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->json('required_documents')->nullable()->after('duplicate_check_payload');
            $table->string('status')->default('pending')->change();
        });
    }
};
