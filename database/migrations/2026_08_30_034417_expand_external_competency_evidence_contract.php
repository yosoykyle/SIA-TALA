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
        Schema::table('external_competency_results', function (Blueprint $table) {
            $table->date('assessment_date')->nullable()->after('outcome');
            $table->string('external_source')->nullable()->after('assessment_date');
            $table->string('credential_type', 8)->nullable()->after('external_source');
            $table->string('credential_reference')->nullable()->after('credential_type');
            $table->date('credential_valid_until')->nullable()->after('credential_reference');
            $table->text('safe_remarks')->nullable()->after('credential_valid_until');
            $table->string('source_key', 64)->nullable()->after('safe_remarks')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_competency_results', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn([
                'assessment_date',
                'external_source',
                'credential_type',
                'credential_reference',
                'credential_valid_until',
                'safe_remarks',
                'source_key',
            ]);
        });
    }
};
