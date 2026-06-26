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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('or_number')->nullable()->unique()->after('payment_attempt_id');
            $table->string('or_attachment_path')->nullable()->after('or_number');
        });

        DB::table('installment_policies')->update([
            'penalty_frequency' => 'one_time',
            'penalty_rate' => 5.00,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['or_number', 'or_attachment_path']);
        });
    }
};
