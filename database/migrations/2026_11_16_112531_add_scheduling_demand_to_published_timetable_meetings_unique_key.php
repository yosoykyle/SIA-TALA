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
        // The old unique index is the only one covering the leading column of the
        // `ptm_version_fk` foreign key, so MySQL refuses to drop it until a replacement
        // index covering that column exists. Add the new unique index first.
        Schema::table('published_timetable_meetings', function (Blueprint $table) {
            $table->unique(
                ['published_timetable_version_id', 'section_id', 'scheduling_demand_id', 'meeting_sequence'],
                'published_version_section_demand_meeting_unique',
            );
        });
        Schema::table('published_timetable_meetings', function (Blueprint $table) {
            $table->dropUnique('published_version_section_meeting_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('published_timetable_meetings', function (Blueprint $table) {
            $table->unique(
                ['published_timetable_version_id', 'section_id', 'meeting_sequence'],
                'published_version_section_meeting_unique',
            );
        });
        Schema::table('published_timetable_meetings', function (Blueprint $table) {
            $table->dropUnique('published_version_section_demand_meeting_unique');
        });
    }
};
