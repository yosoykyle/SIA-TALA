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
        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dateTime('correction_closes_at')->nullable()->after('closes_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dropColumn('correction_closes_at');
        });
    }
};
