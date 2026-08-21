<?php

namespace App\Models;

use Database\Factories\FeePlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class FeePlan extends Model
{
    /** @use HasFactory<FeePlanFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StatePublished = 'Published';

    public const StateSuperseded = 'Superseded';

    protected $fillable = [
        'program_id', 'term_id', 'supersedes_fee_plan_id', 'version', 'state', 'currency',
        'authority_reference', 'content_hash', 'created_by', 'published_by', 'published_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<FeePlan, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_fee_plan_id');
    }

    /** @return HasMany<FeePlanCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(FeePlanCharge::class);
    }

    /** @return HasMany<FeePlanObligation, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(FeePlanObligation::class);
    }

    protected static function booted(): void
    {
        static::updating(function (FeePlan $plan): void {
            $dirtyKeys = array_keys($plan->getDirty());
            $isSupersession = $plan->getOriginal('state') === self::StatePublished
                && $plan->state === self::StateSuperseded
                && array_diff($dirtyKeys, ['state', 'updated_at']) === [];

            if ($plan->getOriginal('state') !== self::StateDraft && ! $isSupersession) {
                throw new LogicException('Published and superseded Fee Plans are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Fee Plan history cannot be deleted.'));
    }
}
