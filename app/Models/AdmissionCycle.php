<?php

namespace App\Models;

use Database\Factories\AdmissionCycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 */
class AdmissionCycle extends Model
{
    /** @use HasFactory<AdmissionCycleFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StatePublished = 'Published';

    public const StateCancelled = 'Cancelled';

    public const PathFirstYear = 'FirstYear';

    public const PathTransferee = 'Transferee';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'label',
        'term_id',
        'state',
        'opens_at',
        'closes_at',
        'applicant_instructions',
        'support_contact',
        'privacy_notice_reference',
        'registrar_owner_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => self::StateDraft,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registrarOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrar_owner_id');
    }

    /** @return BelongsToMany<Program, $this> */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class)
            ->withPivot(['accepts_first_year', 'accepts_transferee'])
            ->withTimestamps();
    }

    /** @return HasMany<AdmissionCycleEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AdmissionCycleEvent::class);
    }

    /** @return HasMany<AdmissionRequirementSet, $this> */
    public function requirementSets(): HasMany
    {
        return $this->hasMany(AdmissionRequirementSet::class);
    }

    /** @return HasMany<AdmissionApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class);
    }
}
