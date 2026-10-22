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
        Schema::table('terms', function (Blueprint $table): void {
            $table->string('registration_applicability')->default('AllEligible');
            $table->string('registration_applicability_authority')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table): void {
            $table->dropColumn(['registration_applicability', 'registration_applicability_authority']);
        });
    }
};
