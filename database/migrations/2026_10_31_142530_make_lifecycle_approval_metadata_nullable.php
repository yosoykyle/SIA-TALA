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
        Schema::table('student_lifecycle_changes', function (Blueprint $table): void {
            $table->string('authority', 255)->nullable()->change();
            $table->date('decided_on')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('student_lifecycle_changes')->whereNull('authority')->orWhereNull('decided_on')->exists()) {
            throw new LogicException('Cannot require approval metadata while recorded lifecycle history has no external approval metadata.');
        }
        Schema::table('student_lifecycle_changes', function (Blueprint $table): void {
            $table->string('authority', 255)->nullable(false)->change();
            $table->date('decided_on')->nullable(false)->change();
        });
    }
};
