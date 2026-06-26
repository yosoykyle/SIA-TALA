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
        Schema::dropIfExists('retention_document_undertakings');
        Schema::dropIfExists('applicant_document_requirements');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse migration for dropping legacy tables during refactoring/cleanup
    }
};
