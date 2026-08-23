<?php

namespace App\Models;

use Database\Factories\TranscriptIssuanceEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptIssuanceEvent extends Model
{
    /** @use HasFactory<TranscriptIssuanceEventFactory> */
    use HasFactory;

    public const TypeIssued = 'Issued';

    public const TypeVoided = 'Voided';

    public const TypeReplacement = 'Replacement';

    public const TypeSuperseded = 'Superseded';

    protected $fillable = [
        'transcript_request_id', 'transcript_snapshot_id', 'predecessor_event_id',
        'type', 'reference', 'reason', 'authority_reference', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<TranscriptRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TranscriptRequest::class, 'transcript_request_id');
    }

    /** @return BelongsTo<TranscriptSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TranscriptSnapshot::class, 'transcript_snapshot_id');
    }
}
