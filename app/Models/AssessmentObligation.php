<?php

namespace App\Models;

use Database\Factories\AssessmentObligationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentObligation extends Model
{
    /** @use HasFactory<AssessmentObligationFactory> */
    use HasFactory;

    protected $fillable = ['assessment_id', 'code', 'label', 'amount', 'required_for_enrollment'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'required_for_enrollment' => 'boolean'];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return HasMany<ApprovedCoverage, $this> */
    public function coverages(): HasMany
    {
        return $this->hasMany(ApprovedCoverage::class);
    }
}
