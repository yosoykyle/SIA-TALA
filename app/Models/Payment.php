<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    public static function manualConfirmationChannelOptions(): array
    {
        return [
            'cash' => 'Cash',
            'gcash_manual' => 'GCash Manual',
            'bank_transfer' => 'Bank Transfer',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'payment_attempt_id',
        'ledger_entry_id',
        'payment_reference',
        'channel',
        'amount',
        'status',
        'confirmed_at',
        'confirmed_by',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
