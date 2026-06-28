<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('faculty_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['faculty_user_id', 'course_id']);
            $table->index(['course_id', 'is_active']);
        });

        Schema::create('faculty_term_load_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->decimal('default_max_units_snapshot', 5, 2);
            $table->decimal('approved_overload_units', 5, 2)->default(0);
            $table->string('authority');
            $table->text('reason');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['faculty_user_id', 'term_id']);
            $table->index(['term_id', 'is_active']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('building')->nullable();
            $table->string('room_type');
            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['room_type', 'is_active', 'capacity']);
        });

        Schema::create('room_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key')->index();
            $table->timestamps();
            $table->unique(['room_id', 'feature_key']);
        });

        Schema::create('term_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_entry_id')->constrained()->restrictOnDelete();
            $table->string('category');
            $table->text('special_reason')->nullable();
            $table->string('delivery_variant');
            $table->string('modality');
            $table->unsignedInteger('expected_count');
            $table->string('room_type_override')->nullable();
            $table->boolean('same_faculty_override')->nullable();
            $table->string('state');
            $table->timestamps();
            $table->unique(['term_id', 'curriculum_entry_id', 'delivery_variant'], 'term_offerings_variant_unique');
            $table->index(['term_id', 'state', 'category']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_offering_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->unsignedInteger('capacity');
            $table->string('state')->index();
            $table->timestamps();
            $table->unique(['term_offering_id', 'code']);
        });

        Schema::create('section_delivery_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('expected_count');
            $table->string('modality');
            $table->json('delivery_override')->nullable();
            $table->string('state')->index();
            $table->timestamps();
            $table->unique(['section_id', 'name']);
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE faculty_term_load_overrides ADD CONSTRAINT faculty_load_nonnegative_check CHECK (default_max_units_snapshot >= 0 AND approved_overload_units >= 0)');
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_check CHECK (capacity > 0)');
        DB::statement('ALTER TABLE sections ADD CONSTRAINT sections_capacity_check CHECK (capacity > 0)');
        DB::statement('ALTER TABLE section_delivery_groups ADD CONSTRAINT delivery_groups_expected_check CHECK (expected_count >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
        });
        Schema::dropIfExists('section_delivery_groups');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('term_offerings');
        Schema::dropIfExists('room_features');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('faculty_term_load_overrides');
        Schema::dropIfExists('faculty_qualifications');
    }
};
