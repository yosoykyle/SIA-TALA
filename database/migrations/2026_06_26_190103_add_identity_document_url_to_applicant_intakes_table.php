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
            $table->string('identity_document_url')->nullable()->after('required_documents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dropColumn('identity_document_url');
        });
    }
};
