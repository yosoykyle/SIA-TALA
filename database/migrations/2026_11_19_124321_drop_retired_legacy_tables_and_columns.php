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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' && Schema::hasTable('enrollment_exceptions')) {
            $hasTargetCheck = collect(DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_exceptions' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'enrollment_exceptions_target_check'",
            ))->isNotEmpty();

            if ($hasTargetCheck) {
                DB::statement('ALTER TABLE enrollment_exceptions DROP CHECK enrollment_exceptions_target_check');
            }
        }

        if (Schema::hasTable('enrollment_exceptions') && Schema::hasColumn('enrollment_exceptions', 'enrollment_gate_result_id')) {
            $foreignKeys = collect(Schema::getForeignKeys('enrollment_exceptions'))
                ->filter(fn (array $foreignKey): bool => in_array('enrollment_gate_result_id', $foreignKey['columns'] ?? [], true))
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                ->values()
                ->all();

            $indexes = collect(Schema::getIndexes('enrollment_exceptions'))
                ->filter(fn (array $index): bool => in_array('enrollment_gate_result_id', $index['columns'] ?? [], true))
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                ->values()
                ->all();

            Schema::table('enrollment_exceptions', function (Blueprint $table) use ($driver, $foreignKeys, $indexes): void {
                if ($driver !== 'sqlite') {
                    foreach ($foreignKeys as $foreignKey) {
                        $table->dropForeign($foreignKey);
                    }
                }

                foreach ($indexes as $index) {
                    $table->dropIndex($index);
                }

                $table->dropColumn('enrollment_gate_result_id');
            });
        }

        Schema::dropIfExists('term_average_labels');
        Schema::dropIfExists('resource_unavailabilities');
        Schema::dropIfExists('student_schedule_bindings');
        Schema::dropIfExists('enrollment_gate_results');

        if (Schema::hasTable('term_calendar_packages') && Schema::hasColumn('term_calendar_packages', 'faculty_availability_due_at')) {
            Schema::table('term_calendar_packages', function (Blueprint $table): void {
                $table->dropColumn('faculty_availability_due_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only retirement of unused legacy tables and columns.
    }
};
