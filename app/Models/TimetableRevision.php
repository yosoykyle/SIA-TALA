<?php

namespace App\Models;

use Database\Factories\TimetableRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property array<int, array<string, mixed>> $changes_snapshot
 * @property array{changed_section_ids?: list<int>, affected_registration_case_ids?: list<int>, affected_count?: int} $impact_snapshot
 */
class TimetableRevision extends Model
{
    /** @use HasFactory<TimetableRevisionFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StatePublished = 'Published';

    public const StateCancelled = 'Cancelled';

    protected $fillable = [
        'term_id', 'source_version_id', 'successor_version_id', 'state', 'change_type',
        'changes_snapshot', 'impact_snapshot', 'content_hash', 'authority_reference',
        'reason', 'prepared_by', 'prepared_at', 'published_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'changes_snapshot' => 'array',
            'impact_snapshot' => 'array',
            'prepared_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<PublishedTimetableVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(PublishedTimetableVersion::class, 'source_version_id');
    }

    /** @return BelongsTo<PublishedTimetableVersion, $this> */
    public function successorVersion(): BelongsTo
    {
        return $this->belongsTo(PublishedTimetableVersion::class, 'successor_version_id');
    }

    protected static function booted(): void
    {
        static::updating(function (TimetableRevision $revision): void {
            if (array_diff(array_keys($revision->getDirty()), ['state', 'successor_version_id', 'published_by', 'published_at', 'updated_at']) !== []) {
                throw new LogicException('Prepared Timetable Revision sources are immutable.');
            }

            if ($revision->getOriginal('state') !== self::StateDraft
                || ! in_array($revision->state, [self::StatePublished, self::StateCancelled], true)) {
                throw new LogicException('Timetable Revision history cannot be rewritten.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Timetable Revision history cannot be deleted.'));
    }
}
