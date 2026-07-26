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
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'term_id'],
                'applicant_intakes_user_term_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dropUnique('applicant_intakes_user_term_unique');
        });
    }
};
