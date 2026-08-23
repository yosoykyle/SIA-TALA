<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $unknownStatuses = DB::table('payment_attempts')
            ->whereNotIn('status', [
                'pending',
                'under_review',
                'paid',
                'confirmed',
                'cancelled',
                'canceled',
                'expired',
                'failed',
                'Pending',
                'ReviewRequired',
                'Confirmed',
                'Cancelled',
                'Expired',
                'Failed',
            ])
            ->distinct()
            ->pluck('status');

        if ($unknownStatuses->isNotEmpty()) {
            throw new RuntimeException('Payment Attempt status migration found an unknown historical state.');
        }

        $unresolvedActiveAttempts = DB::table('payment_attempts')
            ->leftJoin('assessments', 'assessments.id', '=', 'payment_attempts.assessment_id')
            ->whereIn('payment_attempts.status', ['pending', 'under_review', 'Pending', 'ReviewRequired'])
            ->where(function ($query): void {
                $query->whereNull('assessments.term_account_id')
                    ->orWhereNull('assessments.version');
            })
            ->exists();

        if ($unresolvedActiveAttempts) {
            throw new RuntimeException('An active historical Payment Attempt cannot be mapped safely to a Term Account.');
        }

        DB::table('payment_attempts')
            ->join('assessments', 'assessments.id', '=', 'payment_attempts.assessment_id')
            ->whereNull('payment_attempts.term_account_id')
            ->update([
                'payment_attempts.term_account_id' => DB::raw('assessments.term_account_id'),
                'payment_attempts.assessment_version' => DB::raw('assessments.version'),
            ]);

        foreach ([
            'pending' => 'Pending',
            'under_review' => 'ReviewRequired',
            'paid' => 'Confirmed',
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'canceled' => 'Cancelled',
            'expired' => 'Expired',
            'failed' => 'Failed',
        ] as $from => $to) {
            DB::table('payment_attempts')->where('status', $from)->update(['status' => $to]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'Pending' => 'pending',
            'ReviewRequired' => 'under_review',
            'Confirmed' => 'paid',
            'Cancelled' => 'cancelled',
            'Expired' => 'expired',
            'Failed' => 'failed',
        ] as $from => $to) {
            DB::table('payment_attempts')->where('status', $from)->update(['status' => $to]);
        }
    }
};
