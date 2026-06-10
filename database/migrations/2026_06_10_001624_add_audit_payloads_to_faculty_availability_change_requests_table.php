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
        Schema::table('faculty_availability_change_requests', function (Blueprint $table) {
            $table->json('source_windows')->nullable();
            $table->json('requested_windows')->nullable();
            $table->text('review_note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faculty_availability_change_requests', function (Blueprint $table) {
            $table->dropColumn([
                'source_windows',
                'requested_windows',
                'review_note',
            ]);
        });
    }
};
