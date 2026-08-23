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
        Schema::table('official_output_payment_clearances', function (Blueprint $table): void {
            $table->foreignId('transcript_request_id')->nullable()->after('term_account_id')
                ->constrained()->restrictOnDelete();
            $table->decimal('required_amount', 12, 2)->nullable()->after('state');
        });

        Schema::table('official_output_payment_clearances', function (Blueprint $table): void {
            $table->foreignId('term_account_id')->nullable()->change();
            $table->index(['transcript_request_id', 'state', 'decided_at'], 'transcript_clearance_current_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('official_output_payment_clearances', function (Blueprint $table): void {
            $table->dropIndex('transcript_clearance_current_index');
            $table->dropForeign(['transcript_request_id']);
            $table->dropColumn(['transcript_request_id', 'required_amount']);
        });

        Schema::table('official_output_payment_clearances', function (Blueprint $table): void {
            $table->foreignId('term_account_id')->nullable(false)->change();
        });
    }
};
