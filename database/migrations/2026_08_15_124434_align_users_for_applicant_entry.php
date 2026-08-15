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
            $table->string('name', 255)->nullable()->change();
            $table->string('privacy_notice_reference', 2048)->nullable()->after('email_verified_at');
            $table->timestamp('privacy_acknowledged_at')->nullable()->after('privacy_notice_reference');
            $table->string('email_verification_nonce', 64)->nullable()->after('privacy_acknowledged_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'privacy_notice_reference',
                'privacy_acknowledged_at',
                'email_verification_nonce',
            ]);
            $table->string('name', 255)->nullable(false)->change();
        });
    }
};
