<?php

namespace App\Models;

use Database\Factories\FeePlanChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FeePlanCharge extends Model
{
    /** @use HasFactory<FeePlanChargeFactory> */
    use HasFactory;

    protected $fillable = ['fee_plan_id', 'sequence', 'code', 'label', 'category', 'amount'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<FeePlan, $this> */
    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class);
    }

    protected static function booted(): void
    {
        static::saving(function (FeePlanCharge $charge): void {
            if ($charge->exists && $charge->feePlan()->where('state', '!=', FeePlan::StateDraft)->exists()) {
                throw new LogicException('Published Fee Plan charges are immutable.');
            }
        });
        static::deleting(function (FeePlanCharge $charge): void {
            if ($charge->feePlan()->where('state', '!=', FeePlan::StateDraft)->exists()) {
                throw new LogicException('Published Fee Plan charges cannot be deleted.');
            }
        });
    }
}
