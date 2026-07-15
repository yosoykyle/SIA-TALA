<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('active_assessment_id')
                ->nullable()
                ->virtualAs("CASE WHEN `status` IN ('pending', 'under_review') THEN `assessment_id` ELSE NULL END");
            $table->unique('active_assessment_id', 'payment_attempts_one_active_assessment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropUnique('payment_attempts_one_active_assessment_unique');
            $table->dropColumn('active_assessment_id');
        });
    }
};
