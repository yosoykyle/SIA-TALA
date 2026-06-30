<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccommodation extends Model
{
    public const StatusActive = 'ACTIVE';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'balance_snapshot',
        'covered_amount',
        'basis',
        'certification_reference',
        'private_evidence_reference',
        'promissory_required',
        'promissory_maker',
        'allows_finance_gate',
        'allows_next_term_enrollment',
        'allows_reactivation',
        'allows_record_release',
        'waives_downpayment',
        'authority',
        'recorded_by',
        'status',
        'effective_from',
        'expires_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_snapshot' => 'decimal:2',
            'covered_amount' => 'decimal:2',
            'promissory_required' => 'boolean',
            'allows_finance_gate' => 'boolean',
            'allows_next_term_enrollment' => 'boolean',
            'allows_reactivation' => 'boolean',
            'allows_record_release' => 'boolean',
            'waives_downpayment' => 'boolean',
            'effective_from' => 'date',
            'expires_on' => 'date',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return HasMany<PaymentScheduleRow, $this> */
    public function paymentScheduleRows(): HasMany
    {
        return $this->hasMany(PaymentScheduleRow::class);
    }
}
