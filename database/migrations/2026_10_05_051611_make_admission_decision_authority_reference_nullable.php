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
        Schema::table('admission_decisions', function (Blueprint $table) {
            $table->string('authority_reference', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('admission_decisions')->whereNull('authority_reference')->exists()) {
            throw new LogicException('Cannot restore a required approval reference while routine Registrar decisions contain no separate reference. Preserve their immutable history.');
        }

        Schema::table('admission_decisions', function (Blueprint $table) {
            $table->string('authority_reference', 255)->nullable(false)->change();
        });
    }
};
