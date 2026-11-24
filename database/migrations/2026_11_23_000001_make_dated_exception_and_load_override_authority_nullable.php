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
        Schema::table('term_dated_exceptions', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->change();
        });

        Schema::table('faculty_term_load_overrides', function (Blueprint $table): void {
            $table->string('authority')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('term_dated_exceptions')
            ->whereNull('authority_reference')
            ->update(['authority_reference' => '']);

        DB::table('faculty_term_load_overrides')
            ->whereNull('authority')
            ->update(['authority' => '']);

        Schema::table('term_dated_exceptions', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable(false)->change();
        });

        Schema::table('faculty_term_load_overrides', function (Blueprint $table): void {
            $table->string('authority')->nullable(false)->change();
        });
    }
};
