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
        Schema::table('faculty_availability_declarations', function (Blueprint $table): void {
            $table->string('availability_mode', 32)->nullable()->after('declaration');
            $table->json('teaching_windows')->nullable()->after('hard_unavailability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faculty_availability_declarations', function (Blueprint $table): void {
            $table->dropColumn(['availability_mode', 'teaching_windows']);
        });
    }
};
