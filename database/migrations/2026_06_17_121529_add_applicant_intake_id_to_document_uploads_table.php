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
            $table->foreignId('applicant_intake_id')
                ->nullable()
                ->after('student_profile_id')
                ->constrained('applicant_intakes')
                ->nullOnDelete();

            $table->index(['applicant_intake_id', 'created_at'], 'document_uploads_applicant_intake_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropForeign(['applicant_intake_id']);
            $table->dropIndex('document_uploads_applicant_intake_created_at_index');
            $table->dropColumn('applicant_intake_id');
        });
    }
};
