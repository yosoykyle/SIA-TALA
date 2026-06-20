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
        Schema::create('admission_capacity_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_capacity_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('secured')->index();
            $table->timestamp('secured_at');
            $table->json('scope_snapshot');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['admission_capacity_plan_id', 'enrollment_id'], 'admission_capacity_reservations_plan_enrollment_unique');
            $table->index(['student_profile_id', 'status'], 'admission_capacity_reservations_profile_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_capacity_reservations');
    }
};
