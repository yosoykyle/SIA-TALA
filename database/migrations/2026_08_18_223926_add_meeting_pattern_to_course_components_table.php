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
        Schema::table('course_components', function (Blueprint $table) {
            $table->string('meeting_pattern', 32)->nullable()->after('weekly_contact_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_components', function (Blueprint $table) {
            $table->dropColumn('meeting_pattern');
        });
    }
};
