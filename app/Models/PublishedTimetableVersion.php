<?php

namespace App\Models;

use Database\Factories\PublishedTimetableVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublishedTimetableVersion extends Model
{
    /** @use HasFactory<PublishedTimetableVersionFactory> */
    use HasFactory;

    public const StatePublished = 'Published';

    public const StateSuperseded = 'Superseded';

    protected $fillable = [
        'term_id', 'schedule_run_id', 'supersedes_version_id', 'version', 'state',
        'authority_reference', 'publication_reason', 'source_versions', 'impact_summary',
        'content_hash', 'published_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'source_versions' => 'array',
            'impact_summary' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<ScheduleGenerationRun, $this> */
    public function scheduleRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class, 'schedule_run_id');
    }

    /** @return BelongsTo<PublishedTimetableVersion, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_version_id');
    }

    /** @return HasMany<PublishedTimetableMeeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(PublishedTimetableMeeting::class);
    }
}
