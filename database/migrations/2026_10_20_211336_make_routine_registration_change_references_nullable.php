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
        foreach (['enrollment_adjustments', 'course_drop_records'] as $tableName) {
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
        if (DB::table('enrollment_adjustments')->whereNull('authority_reference')->exists()
            || DB::table('course_drop_records')->whereNull('authority_reference')->exists()) {
            throw new LogicException('Cannot restore required approval references while routine registration changes contain no separate reference. Preserve their immutable history.');
        }

        foreach (['enrollment_adjustments', 'course_drop_records'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('authority_reference', 255)->nullable(false)->change();
            });
        }
    }
};
