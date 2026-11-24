<?php

namespace App\Models;

use Database\Factories\AdmissionCycleFactory;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property Carbon|null $correction_closes_at
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

    /**
     * Reader-facing Student type labels for the unchanged canonical paths (PRD 02 §10.1).
     *
     * @return array<string, string>
     */
    public static function studentTypeOptions(): array
    {
        return [
            self::PathFirstYear => 'Freshman',
            self::PathTransferee => 'Transferee',
        ];
    }

    public static function studentTypeLabel(?string $path): string
    {
        return self::studentTypeOptions()[$path] ?? (string) str((string) $path)->headline();
    }

    /**
     * Badge color that sets the Student type apart on every case list (#99 Scope 10). The panel's "info" is a
     * neutral gray, so Freshman and Transferee carry their own palettes.
     *
     * @return string|array<int|string, string>
     */
    public static function studentTypeColor(?string $path): string|array
    {
        return match ($path) {
            self::PathFirstYear => Color::Sky,
            self::PathTransferee => Color::Amber,
            default => 'gray',
        };
    }

    /** Badge icon matching the Admissions Student type tabs. */
    public static function studentTypeIcon(?string $path): string
    {
        return match ($path) {
            self::PathFirstYear => 'heroicon-m-academic-cap',
            self::PathTransferee => 'heroicon-m-arrows-right-left',
            default => 'heroicon-m-user',
        };
    }

    /** @var list<string> */
    protected $fillable = [
        'code',
        'label',
        'term_id',
        'state',
        'opens_at',
        'closes_at',
        'correction_closes_at',
        'requirements_due_at',
        'payment_due_at',
        'period_ends_at',
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
            'correction_closes_at' => 'datetime',
            'requirements_due_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }

    public function boundaryVersion(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->id,
            (string) $this->state,
            $this->opens_at?->toIso8601String() ?? '',
            $this->closes_at?->toIso8601String() ?? '',
            $this->correction_closes_at?->toIso8601String() ?? '',
        ]));
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
