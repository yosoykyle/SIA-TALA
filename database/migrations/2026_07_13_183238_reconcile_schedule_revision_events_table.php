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
        Schema::table('schedule_revision_events', function (Blueprint $table) {
            $table->renameColumn('old_snapshot', 'old_snapshot_json');
            $table->renameColumn('new_snapshot', 'new_snapshot_json');
            $table->renameColumn('affected_count', 'affected_student_count');
            $table->unsignedInteger('affected_faculty_count')->default(0)->after('affected_student_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_revision_events', function (Blueprint $table) {
            $table->dropColumn('affected_faculty_count');
            $table->renameColumn('affected_student_count', 'affected_count');
            $table->renameColumn('new_snapshot_json', 'new_snapshot');
            $table->renameColumn('old_snapshot_json', 'old_snapshot');
        });
    }
};
