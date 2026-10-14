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
        Schema::table('document_evidence', function (Blueprint $table) {
            $table->index(['admission_application_id', 'admission_requirement_id', 'checksum'], 'document_evidence_canonical_checksum_index');
            $table->dropUnique('document_evidence_canonical_checksum_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_evidence', function (Blueprint $table) {
            $table->unique(['admission_application_id', 'admission_requirement_id', 'checksum'], 'document_evidence_canonical_checksum_unique');
            $table->dropIndex('document_evidence_canonical_checksum_index');
        });
    }
};
