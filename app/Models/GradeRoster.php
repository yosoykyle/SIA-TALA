<?php

namespace App\Models;

use Database\Factories\GradeRosterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $released_at
 */
class GradeRoster extends Model
{
    /** @use HasFactory<GradeRosterFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateSubmitted = 'SUBMITTED';

    public const StateReturned = 'RETURNED';

    public const StateReleased = 'POSTED_RELEASED';

    public const StateLateNotSubmitted = 'LATE_NOT_SUBMITTED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_offering_id',
        'section_id',
        'faculty_user_id',
        'state',
        'grading_profile_snapshot',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'released_by',
        'released_at',
        'return_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grading_profile_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TermOffering, $this> */
    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<User, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    /** @return HasMany<GradeRosterRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(GradeRosterRow::class);
    }

    /** @return HasMany<LateGradeAuthorization, $this> */
    public function lateAuthorizations(): HasMany
    {
        return $this->hasMany(LateGradeAuthorization::class);
    }

    public function isReleased(): bool
    {
        return $this->state === self::StateReleased && $this->released_at !== null;
    }
}
