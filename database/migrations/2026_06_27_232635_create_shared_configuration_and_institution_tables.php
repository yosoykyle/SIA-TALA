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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128);
            $table->string('scope_type', 64)->default('institution');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->string('value_type');
            $table->json('value');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->unsignedInteger('version');
            $table->string('status', 32)->index();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['key', 'scope_type', 'scope_id', 'version'], 'settings_scope_version_unique');
            $table->index(['key', 'scope_type', 'scope_id', 'status', 'effective_from', 'effective_until'], 'settings_effectivity_index');
        });

        Schema::create('operational_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_domain');
            $table->string('integration')->nullable();
            $table->string('channel')->nullable();
            $table->string('direction')->nullable();
            $table->string('event_type');
            $table->string('event_version')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('recipient_snapshot')->nullable();
            $table->string('external_id')->nullable();
            $table->string('status');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('related_record_type')->nullable();
            $table->unsignedBigInteger('related_record_id')->nullable();
            $table->json('diagnostics')->nullable();
            $table->json('payload')->nullable();
            $table->unique(['event_domain', 'external_id'], 'operational_events_external_unique');
            $table->index(['event_domain', 'status', 'occurred_at'], 'operational_events_status_index');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['related_record_type', 'related_record_id'], 'operational_events_related_index');
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedTinyInteger('duration_years')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('state')->index();
            $table->timestamps();
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('state');
            $table->unsignedSmallInteger('scheduling_slot_minutes')->default(30);
            $table->decimal('default_max_units', 5, 2)->nullable();
            $table->timestamps();
            $table->unique(['academic_year_id', 'type', 'label']);
            $table->index(['state', 'starts_on', 'ends_on']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('event_type');
            $table->string('scope_type');
            $table->unsignedBigInteger('room_id')->nullable()->index();
            $table->foreignId('faculty_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('process_key')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('blocks_scheduling')->default(false);
            $table->string('state');
            $table->string('authority');
            $table->timestamps();
            $table->index(['term_id', 'event_type', 'start_at', 'end_at'], 'calendar_term_event_time_index');
            $table->index(['room_id', 'day_of_week', 'starts_at', 'ends_at'], 'calendar_room_time_index');
            $table->index(['faculty_user_id', 'day_of_week', 'starts_at', 'ends_at'], 'calendar_faculty_time_index');
            $table->index(['term_id', 'process_key', 'state'], 'calendar_process_window_index');
        });

        DB::statement('ALTER TABLE academic_years ADD CONSTRAINT academic_years_date_check CHECK (starts_on < ends_on)');
        DB::statement('ALTER TABLE terms ADD CONSTRAINT terms_date_check CHECK (starts_on < ends_on)');
        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT calendar_events_scope_check CHECK ((scope_type <> 'ROOM' OR room_id IS NOT NULL) AND (scope_type <> 'FACULTY' OR faculty_user_id IS NOT NULL))");
        DB::statement('ALTER TABLE calendar_events ADD CONSTRAINT calendar_events_time_check CHECK ((start_at IS NULL OR end_at IS NULL OR start_at < end_at) AND (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('operational_events');
        Schema::dropIfExists('system_settings');
    }
};
