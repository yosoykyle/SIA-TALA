<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('message');
            $table->string('state', 20)->default('Draft');
            $table->unsignedInteger('display_order');
            $table->string('link_label', 80)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['state', 'display_order']);
        });

        foreach (['public_notices', 'faq_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->foreignId('root_id')->nullable()->constrained($name)->restrictOnDelete();
                $table->foreignId('previous_version_id')->nullable()->unique()->constrained($name)->restrictOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->unsignedInteger('revision')->default(1);
                $table->boolean('ever_published')->default(false);
                $table->dateTime('visible_from')->nullable();
                $table->dateTime('visible_until')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['root_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        foreach (['public_notices', 'faq_entries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('previous_version_id');
                $table->dropConstrainedForeignId('root_id');
                $table->dropConstrainedForeignId('published_by');
                $table->dropColumn(['version', 'revision', 'ever_published', 'visible_from', 'visible_until', 'published_at']);
            });
        }
        Schema::dropIfExists('public_notices');
    }
};
