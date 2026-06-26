<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('exam_access_accommodations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse migration for the obsolete exam-access accommodation workflow.
    }
};
