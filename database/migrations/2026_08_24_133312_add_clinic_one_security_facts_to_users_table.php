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
            $table->string('password', 255)->nullable()->change();
            $table->timestamp('disabled_at')->nullable()->after('status')->index();
            $table->foreignId('disabled_by')->nullable()->after('disabled_at')->constrained('users')->restrictOnDelete();
            $table->text('disabled_reason')->nullable()->after('disabled_by');
            $table->string('disabled_authority')->nullable()->after('disabled_reason');
            $table->string('disabled_evidence_reference')->nullable()->after('disabled_authority');
            $table->timestamp('last_successful_sign_in_at')->nullable()->after('two_factor_confirmed_at');
            $table->timestamp('two_factor_recovery_codes_acknowledged_at')->nullable()->after('last_successful_sign_in_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disabled_by');
            $table->dropColumn([
                'disabled_at',
                'disabled_reason',
                'disabled_authority',
                'disabled_evidence_reference',
                'last_successful_sign_in_at',
                'two_factor_recovery_codes_acknowledged_at',
            ]);
            $table->string('password', 255)->nullable(false)->change();
        });
    }
};
