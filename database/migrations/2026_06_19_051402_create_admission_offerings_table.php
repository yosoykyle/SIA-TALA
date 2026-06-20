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
        Schema::create('admission_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('education_level')->index();
            $table->string('entry_route')->index();
            $table->string('prior_credential_pathway')->nullable()->index();
            $table->string('citizenship_compliance_profile')->nullable()->index();
            $table->string('year_level')->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'education_level', 'entry_route', 'status'], 'admission_offerings_resolution_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_offerings');
    }
};
