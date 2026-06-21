<?php

namespace App\Models;

use Database\Factories\AdmissionCapacityPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionCapacityPlan extends Model
{
    /** @use HasFactory<AdmissionCapacityPlanFactory> */
    use HasFactory;

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusRetired = 'retired';

    public const ScopeCampus = 'campus';

    public const ScopeProgram = 'program';

    public const ScopeYearLevel = 'year_level';

    public const ScopeDeliverySetup = 'delivery_setup';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusDraft => 'Draft',
            self::StatusApproved => 'Approved',
            self::StatusRetired => 'Retired',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeTypeOptions(): array
    {
        return [
            self::ScopeCampus => 'Campus',
            self::ScopeProgram => 'Program',
            self::ScopeYearLevel => 'Year level',
            self::ScopeDeliverySetup => 'Delivery setup',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'scope_type',
        'program_id',
        'year_level',
        'delivery_setup',
        'capacity_limit',
        'reserved_count',
        'status',
        'effective_from',
        'effective_until',
        'approved_by',
        'approved_at',
        'meta',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'scope_type' => self::ScopeCampus,
        'capacity_limit' => 100,
        'reserved_count' => 0,
        'status' => self::StatusDraft,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity_limit' => 'integer',
            'reserved_count' => 'integer',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'approved_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(AdmissionCapacityReservation::class);
    }

    public function displayLabel(): string
    {
        $this->loadMissing(['term', 'program']);

        return collect([
            $this->term?->term_name,
            self::scopeTypeOptions()[$this->scope_type] ?? $this->scope_type,
            $this->program?->code,
            $this->year_level,
            $this->delivery_setup,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
