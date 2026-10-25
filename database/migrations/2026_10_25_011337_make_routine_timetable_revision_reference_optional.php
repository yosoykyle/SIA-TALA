<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['timetable_revisions', 'faculty_availability_deadlines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('authority_reference', 255)->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['timetable_revisions', 'faculty_availability_deadlines'] as $tableName) {
            if (DB::table($tableName)->whereNull('authority_reference')->exists()) {
                throw new LogicException('Cannot restore required routine references while records without a separate reference exist. Preserve their history.');
            }
        }

        foreach (['timetable_revisions', 'faculty_availability_deadlines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('authority_reference', 255)->nullable(false)->change();
            });
        }
    }
};
