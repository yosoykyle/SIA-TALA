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
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->decimal('weekly_contact_hours', 5, 2)->nullable()->after('semester');
            $table->string('academic_subject_type')->nullable()->index()->after('weekly_contact_hours');
            $table->string('scheduling_group')->nullable()->index()->after('academic_subject_type');
            $table->string('delivery_rule_override')->nullable()->index()->after('scheduling_group');
        });

        Schema::create('curriculum_readiness_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curriculums')->cascadeOnDelete();
            $table->string('year_level');
            $table->string('curriculum_period');
            $table->string('status')->default('needs_review')->index();
            $table->foreignId('last_transition_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_transition_at')->nullable();
            $table->json('last_blockers')->nullable();
            $table->string('last_blocker_hash')->nullable();
            $table->text('last_transition_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['curriculum_id', 'year_level', 'curriculum_period'],
                'curriculum_readiness_scope_unique',
            );
            $table->index(['curriculum_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculum_readiness_scopes');

        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropIndex(['academic_subject_type']);
            $table->dropIndex(['scheduling_group']);
            $table->dropIndex(['delivery_rule_override']);

            $table->dropColumn([
                'weekly_contact_hours',
                'academic_subject_type',
                'scheduling_group',
                'delivery_rule_override',
            ]);
        });
    }
};
