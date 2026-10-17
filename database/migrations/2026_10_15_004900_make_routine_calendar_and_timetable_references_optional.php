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
        Schema::table('term_calendar_packages', function (Blueprint $table) {
            $table->string('authority_reference', 255)->nullable()->change();
            $table->date('authority_date')->nullable()->change();
        });
        Schema::table('published_timetable_versions', function (Blueprint $table) {
            $table->string('authority_reference', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('term_calendar_packages', function (Blueprint $table) {
            $table->string('authority_reference', 255)->nullable(false)->change();
            $table->date('authority_date')->nullable(false)->change();
        });
        Schema::table('published_timetable_versions', function (Blueprint $table) {
            $table->string('authority_reference', 255)->nullable(false)->change();
        });
    }
};
