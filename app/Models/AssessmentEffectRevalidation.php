<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AssessmentEffectRevalidation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'term_account_id', 'assessment_id', 'version', 'obligation_mapping',
        'authority_reference', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'obligation_mapping' => 'array', 'recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Assessment revalidation history is immutable.'));
        static::deleting(fn (): never => throw new LogicException('Assessment revalidation history is retained.'));
    }
}
