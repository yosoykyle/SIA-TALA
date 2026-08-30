<?php

namespace App\Models;

use Database\Factories\TranscriptSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $content
 * @property Carbon $issued_at
 */
class TranscriptSnapshot extends Model
{
    /** @use HasFactory<TranscriptSnapshotFactory> */
    use HasFactory;

    public const StatusIssued = 'Issued';

    public const StatusVoided = 'Voided';

    public const StatusSuperseded = 'Superseded';

    protected $fillable = [
        'transcript_request_id', 'degree_conferral_id', 'version', 'supersedes_snapshot_id',
        'reference', 'template_version', 'source_fingerprint', 'content', 'status',
        'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'content' => 'array', 'issued_at' => 'datetime'];
    }

    /** @return BelongsTo<TranscriptRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TranscriptRequest::class, 'transcript_request_id');
    }

    /** @return BelongsTo<DegreeConferral, $this> */
    public function conferral(): BelongsTo
    {
        return $this->belongsTo(DegreeConferral::class, 'degree_conferral_id');
    }

    /** @return HasMany<TranscriptIssuanceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TranscriptIssuanceEvent::class);
    }
}
