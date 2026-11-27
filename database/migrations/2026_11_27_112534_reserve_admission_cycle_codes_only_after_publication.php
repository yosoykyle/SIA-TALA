<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #110 item 8: a Draft Admission Cycle no longer reserves its Internal cycle code.
 * Only cycles that left Draft (Published, or Cancelled after publication) hold a code uniquely.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dropUnique('admission_cycles_code_unique');
            $table->index('code', 'admission_cycles_code_index');
            $table->string('reserved_code', 40)
                ->nullable()
                ->storedAs("CASE WHEN state <> 'Draft' THEN code ELSE NULL END")
                ->after('code');
            $table->unique('reserved_code', 'admission_cycles_reserved_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dropUnique('admission_cycles_reserved_code_unique');
            $table->dropColumn('reserved_code');
            $table->dropIndex('admission_cycles_code_index');
            $table->unique('code', 'admission_cycles_code_unique');
        });
    }
};
