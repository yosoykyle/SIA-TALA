<?php

namespace App\Models;

use Database\Factories\PreliminaryEvidenceReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/** @property Carbon $reviewed_at */
class PreliminaryEvidenceReview extends Model
{
    /** @use HasFactory<PreliminaryEvidenceReviewFactory> */
    use HasFactory;

    public const ResultUnderReview = 'UnderReview';

    public const ResultAccepted = 'AcceptedAsPreliminaryEvidence';

    public const ResultActionNeeded = 'ActionNeeded';

    /** @var list<string> */
    protected $fillable = [
        'document_evidence_id',
        'result',
        'reason',
        'reviewed_by',
        'reviewed_at',
        'supersedes_preliminary_evidence_review_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<DocumentEvidence, $this> */
    public function documentEvidence(): BelongsTo
    {
        return $this->belongsTo(DocumentEvidence::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<PreliminaryEvidenceReview, $this> */
    public function supersededReview(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_preliminary_evidence_review_id');
    }

    /** @return HasOne<PreliminaryEvidenceReview, $this> */
    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_preliminary_evidence_review_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Preliminary evidence reviews are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Preliminary evidence reviews cannot be deleted.'));
    }
}
