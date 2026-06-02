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
        Schema::create('installment_policy_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->unsignedTinyInteger('month_offset');
            $table->decimal('required_percentage', 5, 2);
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['installment_policy_id', 'sequence'], 'installment_policy_milestone_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installment_policy_milestones');
    }
};
