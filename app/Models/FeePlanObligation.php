<?php

namespace App\Models;

use Database\Factories\FeePlanObligationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FeePlanObligation extends Model
{
    /** @use HasFactory<FeePlanObligationFactory> */
    use HasFactory;

    protected $fillable = ['fee_plan_id', 'code', 'label', 'amount', 'required_for_enrollment'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'required_for_enrollment' => 'boolean'];
    }

    /** @return BelongsTo<FeePlan, $this> */
    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class);
    }

    protected static function booted(): void
    {
        static::saving(function (FeePlanObligation $obligation): void {
            if ($obligation->exists && $obligation->feePlan()->where('state', '!=', FeePlan::StateDraft)->exists()) {
                throw new LogicException('Published Fee Plan obligations are immutable.');
            }
        });
        static::deleting(function (FeePlanObligation $obligation): void {
            if ($obligation->feePlan()->where('state', '!=', FeePlan::StateDraft)->exists()) {
                throw new LogicException('Published Fee Plan obligations cannot be deleted.');
            }
        });
    }
}
