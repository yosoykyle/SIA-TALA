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
        Schema::table('section_meetings', function (Blueprint $table) {
            $table->text('availability_override_reason')->nullable();
            $table->foreignId('availability_override_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('availability_override_at')->nullable();
            $table->json('availability_override_payload')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_meetings', function (Blueprint $table) {
            $table->dropForeign(['availability_override_by']);
            $table->dropColumn([
                'availability_override_reason',
                'availability_override_by',
                'availability_override_at',
                'availability_override_payload',
            ]);
        });
    }
};
