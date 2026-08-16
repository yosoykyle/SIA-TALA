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
            $table->unsignedBigInteger('program_id')->nullable()->change();
            $table->string('admission_category')->nullable()->change();
            $table->string('credential_basis')->nullable()->change();
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Canonical partial Draft facts are intentionally preserved by this forward-only relaxation.
    }
};
