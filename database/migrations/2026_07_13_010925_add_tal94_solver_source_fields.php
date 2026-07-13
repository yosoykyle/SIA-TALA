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
        Schema::table('terms', function (Blueprint $table): void {
            $table->json('scheduling_days')->nullable()->after('scheduling_slot_minutes');
            $table->time('scheduling_day_starts_at')->default('07:00:00')->after('scheduling_days');
            $table->time('scheduling_day_ends_at')->default('20:00:00')->after('scheduling_day_starts_at');
        });

        Schema::table('course_components', function (Blueprint $table): void {
            $table->json('required_room_feature_keys')->nullable()->after('room_type_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_components', function (Blueprint $table): void {
            $table->dropColumn('required_room_feature_keys');
        });

        Schema::table('terms', function (Blueprint $table): void {
            $table->dropColumn([
                'scheduling_days',
                'scheduling_day_starts_at',
                'scheduling_day_ends_at',
            ]);
        });
    }
};
