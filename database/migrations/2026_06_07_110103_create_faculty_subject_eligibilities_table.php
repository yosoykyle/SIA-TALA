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
        Schema::create('faculty_subject_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->unsignedSmallInteger('priority')->nullable();
            $table->decimal('max_weekly_hours', 5, 2)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('term_scope_id')->storedAs('coalesce(term_id, 0)');
            $table->timestamps();

            $table->unique(
                ['faculty_id', 'subject_id', 'term_scope_id', 'status'],
                'faculty_subject_eligibility_unique'
            );
            $table->index(['faculty_id', 'status']);
            $table->index(['subject_id', 'status']);
            $table->index(['term_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_subject_eligibilities');
    }
};
