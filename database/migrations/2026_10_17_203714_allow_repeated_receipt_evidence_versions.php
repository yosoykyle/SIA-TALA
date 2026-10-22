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
        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->dropUnique('payment_evidence_versions_checksum_unique');
            $table->index('checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payment_evidence_versions')->groupBy('checksum')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Retained receipt attempts must be preserved before reverting this migration.');
        }
        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->dropIndex(['checksum']);
            $table->unique('checksum');
        });
    }
};
