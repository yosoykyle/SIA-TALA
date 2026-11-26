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
        Schema::table('registration_credit_evaluations', function (Blueprint $table) {
            $table->boolean('same_curriculum')->nullable()->after('curriculum_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registration_credit_evaluations', function (Blueprint $table) {
            $table->dropColumn('same_curriculum');
        });
    }
};
