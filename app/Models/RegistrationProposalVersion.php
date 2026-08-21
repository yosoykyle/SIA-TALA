<?php

namespace App\Models;

use Database\Factories\RegistrationProposalVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property array<string, mixed> $source_snapshot
 */
class RegistrationProposalVersion extends Model
{
    /** @use HasFactory<RegistrationProposalVersionFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StateIssued = 'Issued';

    public const StateConfirmed = 'Confirmed';

    public const StateSuperseded = 'Superseded';

    public const PurposeInitial = 'Initial';

    public const PurposeAdjustment = 'Adjustment';

    protected $fillable = [
        'enrollment_id', 'supersedes_version_id', 'version', 'state', 'purpose', 'selection_basis',
        'published_timetable_version_id', 'curriculum_version_id', 'source_snapshot',
        'content_hash', 'prepared_by', 'prepared_at', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'source_snapshot' => 'array', 'prepared_at' => 'datetime', 'issued_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<RegistrationProposalVersion, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_version_id');
    }

    /** @return BelongsTo<PublishedTimetableVersion, $this> */
    public function timetableVersion(): BelongsTo
    {
        return $this->belongsTo(PublishedTimetableVersion::class, 'published_timetable_version_id');
    }

    /** @return BelongsTo<CurriculumVersion, $this> */
    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    /** @return HasMany<RegistrationProposalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RegistrationProposalItem::class);
    }

    /** @return HasOne<RegistrationProposalConfirmation, $this> */
    public function confirmation(): HasOne
    {
        return $this->hasOne(RegistrationProposalConfirmation::class);
    }

    protected static function booted(): void
    {
        static::updating(function (RegistrationProposalVersion $proposal): void {
            $dirtyKeys = array_keys($proposal->getDirty());

            if (array_diff($dirtyKeys, ['state', 'issued_by', 'issued_at', 'updated_at']) !== []) {
                throw new LogicException('Registration Proposal source versions are immutable.');
            }

            $from = (string) $proposal->getOriginal('state');
            $to = (string) $proposal->state;
            $transition = "{$from}:{$to}";

            if (! in_array($transition, [
                self::StateDraft.':'.self::StateIssued,
                self::StateIssued.':'.self::StateConfirmed,
                self::StateDraft.':'.self::StateSuperseded,
                self::StateIssued.':'.self::StateSuperseded,
                self::StateConfirmed.':'.self::StateSuperseded,
            ], true)) {
                throw new LogicException('Registration Proposal state history cannot be rewritten.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Registration Proposal history cannot be deleted.'));
    }
}
