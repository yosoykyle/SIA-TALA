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
        Schema::create('registration_adjustment_finance_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('current_course_enrollment_id');
            $table->unsignedBigInteger('replacement_section_id');
            $table->string('financial_effect', 32);
            $table->string('authority_reference');
            $table->unsignedBigInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            $table->unsignedBigInteger('enrollment_adjustment_id')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('enrollment_adjustment_id', 'registration_adjustment_finance_use_unique');
            $table->unique(
                ['enrollment_id', 'current_course_enrollment_id', 'replacement_section_id', 'financial_effect', 'authority_reference'],
                'registration_adjustment_finance_authority_unique',
            );
            $table->foreign('enrollment_id', 'reg_adj_fin_enrollment_fk')->references('id')->on('enrollments')->restrictOnDelete();
            $table->foreign('current_course_enrollment_id', 'reg_adj_fin_course_fk')->references('id')->on('course_enrollments')->restrictOnDelete();
            $table->foreign('replacement_section_id', 'reg_adj_fin_section_fk')->references('id')->on('sections')->restrictOnDelete();
            $table->foreign('confirmed_by', 'reg_adj_fin_confirmer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('enrollment_adjustment_id', 'reg_adj_fin_adjustment_fk')->references('id')->on('enrollment_adjustments')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_adjustment_finance_confirmations');
    }
};
