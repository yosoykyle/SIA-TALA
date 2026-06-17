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
        Schema::create('delivery_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('modality')->nullable()->index();
            $table->json('allowed_days')->nullable();
            $table->string('subject_routing')->default('same_subject_set')->index();
            $table->string('enforcement_level')->default('strict')->index();
            $table->boolean('default_room_required')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_frozen')->default(false)->index();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('cloned_from_id')->nullable()->constrained('delivery_patterns')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['code', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_patterns');
    }
};
