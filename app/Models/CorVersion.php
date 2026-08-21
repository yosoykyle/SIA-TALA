<?php

namespace App\Models;

use Database\Factories\CorVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property array<string, mixed> $snapshot
 * @property Carbon|null $issued_at
 */
class CorVersion extends Model
{
    /** @use HasFactory<CorVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id', 'supersedes_version_id', 'version', 'registration_proposal_version_id',
        'assessment_id', 'published_timetable_version_id', 'snapshot', 'content_hash', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'issued_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<CorVersion, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_version_id');
    }

    /** @return BelongsTo<RegistrationProposalVersion, $this> */
    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(RegistrationProposalVersion::class, 'registration_proposal_version_id');
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<PublishedTimetableVersion, $this> */
    public function timetableVersion(): BelongsTo
    {
        return $this->belongsTo(PublishedTimetableVersion::class, 'published_timetable_version_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('COR versions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('COR versions cannot be deleted.'));
    }
}
