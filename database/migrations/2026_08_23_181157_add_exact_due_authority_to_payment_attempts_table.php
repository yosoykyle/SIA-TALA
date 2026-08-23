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
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->foreignId('term_account_id')
                ->nullable()
                ->after('assessment_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedInteger('assessment_version')->nullable()->after('student_profile_id');
            $table->timestamp('snapshot_created_at')->nullable()->after('assessment_version');
            $table->char('snapshot_checksum', 64)->nullable()->after('snapshot_created_at');
            $table->index(['term_account_id', 'status'], 'payment_attempt_term_account_status_index');
        });

        Schema::create('payment_attempt_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_obligation_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(
                ['payment_attempt_id', 'assessment_obligation_id'],
                'payment_attempt_obligation_target_unique',
            );
            $table->unique(
                ['payment_attempt_id', 'sequence'],
                'payment_attempt_obligation_sequence_unique',
            );
        });

        DB::statement('ALTER TABLE payment_attempt_obligations ADD CONSTRAINT payment_attempt_obligations_amount_check CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempt_obligations');

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex('payment_attempt_term_account_status_index');
            $table->dropForeign(['term_account_id']);
            $table->dropColumn([
                'term_account_id',
                'assessment_version',
                'snapshot_created_at',
                'snapshot_checksum',
            ]);
        });
    }
};
