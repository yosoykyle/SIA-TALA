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
        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->json('channel_snapshot')->nullable()->after('channel');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->json('channel_snapshot')->nullable()->after('channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('channel_snapshot');
        });

        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->dropColumn('channel_snapshot');
        });
    }
};
