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
        Schema::create('section_delivery_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_pattern_id')->constrained('delivery_patterns')->restrictOnDelete();
            $table->string('name');
            $table->string('modality')->index();
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('assigned_count')->default(0);
            $table->boolean('room_required')->default(false);
            $table->string('room')->nullable();
            $table->string('status')->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['section_id', 'name']);
            $table->index(['section_id', 'status']);
            $table->index(['delivery_pattern_id', 'status']);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('section_delivery_group_id')
                ->nullable()
                ->after('section_id')
                ->constrained('section_delivery_groups')
                ->nullOnDelete();

            $table->index(['section_delivery_group_id', 'status'], 'enrollments_delivery_group_status_index');
        });

        Schema::table('candidate_schedule_rows', function (Blueprint $table) {
            $table->foreignId('section_delivery_group_id')
                ->nullable()
                ->after('section_id')
                ->constrained('section_delivery_groups')
                ->nullOnDelete();

            $table->index('section_delivery_group_id', 'candidate_schedule_rows_delivery_group_index');
        });

        Schema::table('section_meetings', function (Blueprint $table) {
            $table->foreignId('section_delivery_group_id')
                ->nullable()
                ->after('section_id')
                ->constrained('section_delivery_groups')
                ->nullOnDelete();

            $table->index('section_delivery_group_id', 'section_meetings_delivery_group_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_meetings', function (Blueprint $table) {
            $table->dropForeign(['section_delivery_group_id']);
            $table->dropIndex('section_meetings_delivery_group_index');
            $table->dropColumn('section_delivery_group_id');
        });

        Schema::table('candidate_schedule_rows', function (Blueprint $table) {
            $table->dropForeign(['section_delivery_group_id']);
            $table->dropIndex('candidate_schedule_rows_delivery_group_index');
            $table->dropColumn('section_delivery_group_id');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['section_delivery_group_id']);
            $table->dropIndex('enrollments_delivery_group_status_index');
            $table->dropColumn('section_delivery_group_id');
        });

        Schema::dropIfExists('section_delivery_groups');
    }
};
