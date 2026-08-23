<?php

namespace App\Models;

use Database\Factories\TermAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TermAccount extends Model
{
    /** @use HasFactory<TermAccountFactory> */
    use HasFactory;

    public const StateOpen = 'Open';

    public const StateCleared = 'Cleared';

    protected $fillable = ['enrollment_id', 'credential_user_id', 'term_id', 'state'];

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function credentialUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credential_user_id');
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return HasMany<Assessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /** @return HasMany<ApprovedCoverage, $this> */
    public function coverages(): HasMany
    {
        return $this->hasMany(ApprovedCoverage::class);
    }

    /** @return HasMany<PaymentEvidenceVersion, $this> */
    public function paymentEvidenceVersions(): HasMany
    {
        return $this->hasMany(PaymentEvidenceVersion::class);
    }

    /** @return HasOne<PaymentEvidenceVersion, $this> */
    public function latestPaymentEvidenceVersion(): HasOne
    {
        return $this->hasOne(PaymentEvidenceVersion::class)->ofMany([
            'version' => 'max',
            'id' => 'max',
        ]);
    }

    /** @return HasMany<FinanceExport, $this> */
    public function financeExports(): HasMany
    {
        return $this->hasMany(FinanceExport::class);
    }

    /** @return HasMany<OfficialOutputPaymentClearance, $this> */
    public function outputPaymentClearances(): HasMany
    {
        return $this->hasMany(OfficialOutputPaymentClearance::class);
    }

    /** @return HasOne<OfficialOutputPaymentClearance, $this> */
    public function latestOutputPaymentClearance(): HasOne
    {
        return $this->hasOne(OfficialOutputPaymentClearance::class)->ofMany([
            'version' => 'max',
            'id' => 'max',
        ]);
    }
}
