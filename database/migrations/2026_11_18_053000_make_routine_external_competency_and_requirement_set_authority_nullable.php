<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_competency_results', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->change();
            $table->date('authority_date')->nullable()->change();
        });

        Schema::table('admission_requirement_sets', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('external_competency_results')->whereNull('authority_reference')->orWhereNull('authority_date')->exists()) {
            throw new LogicException('Cannot restore required external competency authority fields while rows without approval reference exist.');
        }

        if (DB::table('admission_requirement_sets')->whereNull('authority_reference')->exists()) {
            throw new LogicException('Cannot restore required requirement set authority_reference while rows without reference exist.');
        }

        Schema::table('external_competency_results', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable(false)->change();
            $table->date('authority_date')->nullable(false)->change();
        });

        Schema::table('admission_requirement_sets', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable(false)->change();
        });
    }
};
