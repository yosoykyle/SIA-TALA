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
        Schema::table('late_grade_authorizations', function (Blueprint $table) {
            $table->string('authority_reference')->nullable()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('late_grade_authorizations', function (Blueprint $table) {
            $table->dropColumn('authority_reference');
        });
    }
};
