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
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('status', 80)->default('pending')->index()->after('password');
            $table->timestamp('archived_at')->nullable()->index()->after('status');
            $table->text('archived_reason')->nullable()->after('archived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->dropIndex('users_status_index');
            $table->dropIndex('users_archived_at_index');
            $table->dropColumn([
                'username',
                'status',
                'archived_at',
                'archived_reason',
            ]);
        });
    }
};
