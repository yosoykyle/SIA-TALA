<?php

namespace App\Models;

use Database\Factories\TermAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
