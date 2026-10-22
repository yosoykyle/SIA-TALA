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
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->foreignId('student_profile_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payment_attempts')->whereNull('student_profile_id')->exists()) {
            throw new RuntimeException('Pre-Student payment history must be preserved before reverting this migration.');
        }
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->foreignId('student_profile_id')->nullable(false)->change();
        });
    }
};
