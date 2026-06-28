<?php

namespace App\Models;

use Database\Factories\AdmissionRequirementPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionRequirementPolicy extends Model
{
    /** @use HasFactory<AdmissionRequirementPolicyFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateSuperseded = 'SUPERSEDED';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateSuperseded => 'Superseded',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admission_category',
        'credential_basis',
        'requirement_type',
        'evidence_method',
        'blocking_level',
        'effective_from',
        'effective_until',
        'state',
        'authority',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => self::StateDraft,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'source_policy_id');
    }

    public function displayLabel(): string
    {
        return collect([
            $this->admission_category,
            $this->credential_basis,
            $this->requirement_type,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
