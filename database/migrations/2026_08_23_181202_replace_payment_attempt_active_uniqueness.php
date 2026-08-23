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
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropUnique('payment_attempts_one_active_assessment_unique');
            $table->dropColumn('active_assessment_id');
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('active_term_account_id')
                ->nullable()
                ->virtualAs("CASE WHEN `status` IN ('Pending', 'ReviewRequired') THEN `term_account_id` ELSE NULL END");
            $table->unique('active_term_account_id', 'payment_attempts_one_active_term_account_unique');
        });

        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status_check CHECK (status IN ('Pending', 'Cancelled', 'Expired', 'Failed', 'Confirmed', 'ReviewRequired'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_attempts DROP CHECK payment_attempts_status_check');

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropUnique('payment_attempts_one_active_term_account_unique');
            $table->dropColumn('active_term_account_id');
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('active_assessment_id')
                ->nullable()
                ->virtualAs("CASE WHEN `status` IN ('pending', 'under_review') THEN `assessment_id` ELSE NULL END");
            $table->unique('active_assessment_id', 'payment_attempts_one_active_assessment_unique');
        });
    }
};
