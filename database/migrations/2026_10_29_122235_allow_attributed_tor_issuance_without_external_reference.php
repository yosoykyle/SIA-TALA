<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transcript_issuance_events', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('transcript_issuance_events')->whereNull('authority_reference')->exists()) {
            throw new RuntimeException('Restore a compatible backup before reverting attributed TOR issuance. Existing records must be preserved.');
        }
        Schema::table('transcript_issuance_events', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable(false)->change();
        });
    }
};
