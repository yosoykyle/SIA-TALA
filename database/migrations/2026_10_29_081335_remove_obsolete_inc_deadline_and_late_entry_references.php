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
        Schema::table('inc_deadline_amendments', function (Blueprint $table): void {
            $table->string('authority_reference')->nullable()->change();
            $table->date('authority_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inc_deadline_amendments')->whereNull('authority_reference')->orWhereNull('authority_date')->exists()) {
            throw new RuntimeException('INC deadline amendments without external authority details must remain nullable.');
        }

        Schema::table('inc_deadline_amendments', function (Blueprint $table): void {
            $table->string('authority_reference')->nullable(false)->change();
            $table->date('authority_date')->nullable(false)->change();
        });
    }
};
