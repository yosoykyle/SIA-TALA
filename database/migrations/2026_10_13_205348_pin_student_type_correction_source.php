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
        Schema::table('application_correction_requests', function (Blueprint $table) {
            $table->foreignId('source_submission_version_id')->nullable()->constrained('application_submission_versions', indexName: 'correction_source_version_fk')->restrictOnDelete();
            $table->foreignId('target_requirement_set_id')->nullable()->constrained('admission_requirement_sets', indexName: 'correction_target_set_fk')->restrictOnDelete();
            $table->string('target_application_path', 30)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_correction_requests', function (Blueprint $table) {
            $table->dropForeign('correction_source_version_fk');
            $table->dropForeign('correction_target_set_fk');
            $table->dropColumn(['source_submission_version_id', 'target_requirement_set_id', 'target_application_path']);
        });
    }
};
