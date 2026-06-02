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
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('student_id')->unique();
            $table->string('lrn', 12)->nullable()->unique();
            $table->string('education_level')->index(); // shs, college
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('year_level')->nullable()->index();
            $table->string('operational_status')->default('Active')->index(); // Active, Inactive, Graduated, Archived
            $table->string('status_reason')->nullable();
            $table->string('modality')->nullable();
            $table->decimal('current_balance', 12, 2)->default('0.00');
            $table->boolean('hard_copy_received')->default(false);
            $table->timestamp('last_status_changed_at')->nullable();
            $table->timestamp('graduated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['education_level', 'year_level']);
            $table->index(['program_id', 'operational_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
