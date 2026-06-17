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
        Schema::table('promissory_notes', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable()->after('requested_by');
            $table->string('request_source')->default('staff_assisted')->after('requested_at');
            $table->foreignId('rejected_by')->nullable()->after('expired_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->foreignId('cancelled_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->foreignId('settled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settled_by');
            $table->timestamp('expiry_warning_sent_at')->nullable()->after('settled_at');
            $table->timestamp('expiry_notified_at')->nullable()->after('expiry_warning_sent_at');

            $table->index(['enrollment_id', 'status', 'due_date'], 'promissory_enrollment_status_due_index');
        });

        Schema::table('promissory_notes', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promissory_notes', function (Blueprint $table) {
            $table->dropIndex('promissory_enrollment_status_due_index');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn([
                'requested_at',
                'request_source',
                'rejected_at',
                'rejection_reason',
                'cancelled_at',
                'cancellation_reason',
                'settled_at',
                'expiry_warning_sent_at',
                'expiry_notified_at',
            ]);
        });

        Schema::table('promissory_notes', function (Blueprint $table) {
            $table->string('status')->default('approved')->change();
        });
    }
};
