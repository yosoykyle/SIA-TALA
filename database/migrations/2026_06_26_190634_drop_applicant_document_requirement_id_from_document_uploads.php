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
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropForeign(['applicant_document_requirement_id']);
            $table->dropColumn('applicant_document_requirement_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->foreignId('applicant_document_requirement_id')->nullable()->constrained('applicant_document_requirements')->nullOnDelete();
        });
    }
};
