<?php

namespace App\Models;

use Database\Factories\AdmissionOfferingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionOffering extends Model
{
    /** @use HasFactory<AdmissionOfferingFactory> */
    use HasFactory;

    public const StatusDraft = 'draft';

    public const StatusPublished = 'published';

    public const StatusRetired = 'retired';

    public const EntryRouteRegular = 'regular';

    public const EntryRouteTransfer = 'transfer';

    public const EntryRouteReturning = 'returning';

    public const EntryRouteCrossEnrollee = 'cross_enrollee';

    public const PriorCredentialRegular = 'regular';

    public const PriorCredentialOldCurriculum = 'old_curriculum';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusDraft => 'Draft',
            self::StatusPublished => 'Published',
            self::StatusRetired => 'Retired',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function entryRouteOptions(): array
    {
        return [
            self::EntryRouteRegular => 'Regular',
            self::EntryRouteTransfer => 'Transfer',
            self::EntryRouteReturning => 'Returning',
            self::EntryRouteCrossEnrollee => 'Cross-enrollee',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function priorCredentialOptions(): array
    {
        return [
            self::PriorCredentialRegular => 'Grade 12 / prior education',
            self::PriorCredentialOldCurriculum => 'Old curriculum',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'program_id',
        'name',
        'entry_route',
        'prior_credential_pathway',
        'citizenship_compliance_profile',
        'year_level',
        'status',
        'published_at',
        'meta',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::StatusDraft,
        'prior_credential_pathway' => self::PriorCredentialRegular,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function requirementPolicies(): HasMany
    {
        return $this->hasMany(AdmissionRequirementPolicy::class);
    }

    public function displayLabel(): string
    {
        $this->loadMissing(['term', 'program']);

        return collect([
            $this->name,
            $this->term?->term_name,
            $this->program?->code,
            $this->year_level,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
