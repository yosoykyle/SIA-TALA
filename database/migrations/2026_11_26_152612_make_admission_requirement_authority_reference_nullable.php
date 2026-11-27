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
        Schema::table('admission_requirements', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admission_requirements', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable(false)->change();
        });
    }
};
