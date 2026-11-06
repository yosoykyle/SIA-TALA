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
        Schema::table('academic_decisions', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->change();
            $table->date('authority_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('academic_decisions')->whereNull('authority_reference')->orWhereNull('authority_date')->exists()) {
            throw new LogicException('Cannot restore required authority fields while decisions without external authority exist. Preserve their immutable history.');
        }

        Schema::table('academic_decisions', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable(false)->change();
            $table->date('authority_date')->nullable(false)->change();
        });
    }
};
