<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #103 Scope 6: the school credits a transferee's subject in full or not at all, so transfer credit lines
 * no longer record bridging units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_credit_lines', function (Blueprint $table): void {
            $table->dropColumn('bridging_units');
        });
    }

    public function down(): void
    {
        Schema::table('registration_credit_lines', function (Blueprint $table): void {
            $table->decimal('bridging_units', 5, 2)->nullable()->after('tor_grade');
        });
    }
};
