<?php

namespace App\Models;

use Database\Factories\AdmissionRequirementPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionRequirementPolicy extends Model
{
    /** @use HasFactory<AdmissionRequirementPolicyFactory> */
    use HasFactory;

    public const StatusDraft = 'draft';

    public const StatusActive = 'active';

    public const StatusRetired = 'retired';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusDraft => 'Draft',
            self::StatusActive => 'Active',
            self::StatusRetired => 'Retired',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admission_offering_id',
        'version',
        'status',
        'effective_from',
        'effective_until',
        'approved_by',
        'approved_at',
        'source_label',
        'meta',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'status' => self::StatusDraft,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'approved_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function admissionOffering(): BelongsTo
    {
        return $this->belongsTo(AdmissionOffering::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function documentRequirementItems(): HasMany
    {
        return $this->hasMany(DocumentRequirementItem::class);
    }

    public function displayLabel(): string
    {
        $this->loadMissing('admissionOffering');

        return collect([
            $this->admissionOffering?->displayLabel(),
            "v{$this->version}",
            $this->source_label,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
