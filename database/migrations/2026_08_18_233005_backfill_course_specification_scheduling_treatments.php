<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('course_specifications')
            ->whereNull('scheduling_treatment')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('course_components')
                    ->whereColumn('course_components.course_specification_id', 'course_specifications.id');
            })
            ->update(['scheduling_treatment' => 'Recurring']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward correction: do not erase a later Registrar-authorized treatment.
    }
};
