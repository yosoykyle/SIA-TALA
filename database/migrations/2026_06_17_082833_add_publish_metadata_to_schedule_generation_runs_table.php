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
        Schema::table('schedule_generation_runs', function (Blueprint $table) {
            $table->foreignId('published_by')
                ->nullable()
                ->after('committed_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('published_at')
                ->nullable()
                ->after('published_by');
            $table->text('publish_note')
                ->nullable()
                ->after('published_at');
            $table->boolean('emergency_published')
                ->default(false)
                ->after('publish_note');

            $table->index('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_generation_runs', function (Blueprint $table) {
            $table->dropForeign(['published_by']);
            $table->dropIndex(['published_at']);
            $table->dropColumn([
                'published_by',
                'published_at',
                'publish_note',
                'emergency_published',
            ]);
        });
    }
};
