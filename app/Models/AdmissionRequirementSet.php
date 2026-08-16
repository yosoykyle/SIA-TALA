<?php

namespace App\Models;

use Database\Factories\AdmissionRequirementSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property Carbon|null $effective_at
 * @property Carbon|null $published_at
 */
class AdmissionRequirementSet extends Model
{
    /** @use HasFactory<AdmissionRequirementSetFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StatePublished = 'Published';

    /** @var list<string> */
    protected $fillable = [
        'admission_cycle_id',
        'application_path',
        'version',
        'state',
        'authority_reference',
        'effective_at',
        'published_by',
        'published_at',
        'replaces_requirement_set_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => self::StateDraft,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionCycle, $this> */
    public function admissionCycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return BelongsTo<AdmissionRequirementSet, $this> */
    public function replacedRequirementSet(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_requirement_set_id');
    }

    /** @return HasOne<AdmissionRequirementSet, $this> */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_requirement_set_id');
    }

    /** @return HasMany<AdmissionRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(AdmissionRequirement::class);
    }

    protected static function booted(): void
    {
        static::updating(function (AdmissionRequirementSet $requirementSet): void {
            if ($requirementSet->getOriginal('state') === self::StatePublished) {
                throw new LogicException('Published admission requirement sets are immutable.');
            }
        });

        static::deleting(function (AdmissionRequirementSet $requirementSet): void {
            if ($requirementSet->state === self::StatePublished) {
                throw new LogicException('Published admission requirement sets cannot be deleted.');
            }
        });
    }
}
