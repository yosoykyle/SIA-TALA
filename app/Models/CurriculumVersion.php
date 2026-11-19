<?php

namespace App\Models;

use Database\Factories\CurriculumVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumVersion extends Model
{
    /** @use HasFactory<CurriculumVersionFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateSuperseded = 'SUPERSEDED';

    public const StateArchived = 'ARCHIVED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'program_id',
        'version_code',
        'name',
        'effective_entry_term_id',
        'state',
        'content_hash',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function effectiveEntryTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'effective_entry_term_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<CurriculumEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(CurriculumEntry::class);
    }

    /** @return HasMany<ExternalCompetencyRequirement, $this> */
    public function externalCompetencyRequirements(): HasMany
    {
        return $this->hasMany(ExternalCompetencyRequirement::class);
    }

    /** @return HasMany<StudentProfile, $this> */
    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    /** @return HasMany<TermCohort, $this> */
    public function termCohorts(): HasMany
    {
        return $this->hasMany(TermCohort::class);
    }

    /** @return HasMany<RegistrationProposalVersion, $this> */
    public function registrationProposalVersions(): HasMany
    {
        return $this->hasMany(RegistrationProposalVersion::class);
    }

    /** @return HasMany<GraduationApplication, $this> */
    public function graduationApplications(): HasMany
    {
        return $this->hasMany(GraduationApplication::class);
    }

    /** @return HasMany<DegreeConferral, $this> */
    public function degreeConferrals(): HasMany
    {
        return $this->hasMany(DegreeConferral::class);
    }

    /**
     * Determine whether this version has historical or downstream institutional usage.
     */
    public function isHistoricallyUsed(): bool
    {
        if (in_array($this->state, [self::StateSuperseded, self::StateArchived], true)) {
            return true;
        }

        return $this->studentProfiles()->exists()
            || $this->termCohorts()->exists()
            || $this->registrationProposalVersions()->exists()
            || $this->graduationApplications()->exists()
            || $this->degreeConferrals()->exists()
            || $this->entries()->whereHas('termOfferings')->exists();
    }

    /**
     * Determine whether this curriculum version may still be edited in place.
     */
    public function isEditableDraft(): bool
    {
        return $this->state === self::StateDraft && ! $this->isHistoricallyUsed();
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateSuperseded => 'Replaced',
            self::StateArchived => 'Archived',
        ];
    }
}
