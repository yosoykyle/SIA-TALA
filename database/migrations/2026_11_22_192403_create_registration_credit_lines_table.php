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
        Schema::create('registration_credit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_credit_evaluation_id')->constrained(indexName: 'reg_credit_line_evaluation_fk')->restrictOnDelete();
            $table->foreignId('curriculum_entry_id')->constrained()->restrictOnDelete();
            $table->string('source_course_code', 64);
            $table->string('source_course_title');
            $table->decimal('source_units', 5, 2)->nullable();
            $table->string('tor_grade', 16);
            $table->decimal('bridging_units', 5, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();
            $table->unique(['registration_credit_evaluation_id', 'curriculum_entry_id'], 'reg_credit_line_entry_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_credit_lines');
    }
};
