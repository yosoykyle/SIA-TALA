<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeRule extends Model
{
    public const CalculationFixed = 'fixed';

    public const CalculationPerUnit = 'per_unit';

    public const CalculationManual = 'manual';

    public const LedgerCategoryCharge = 'charge';

    public const LedgerCategoryDownpayment = 'downpayment';

    public const DisplayCategoryTuition = 'tuition';

    public const DisplayCategoryLaboratory = 'laboratory';

    public const DisplayCategoryMiscellaneous = 'miscellaneous';

    public const DisplayCategoryOther = 'other';

    public const DisplayCategoryRegistration = 'registration';

    public const DisplayCategoryDownpayment = 'downpayment';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'ledger_category',
        'display_category',
        'program_id',
        'term_id',
        'calculation_type',
        'amount',
        'rate',
        'effective_from',
        'effective_until',
        'is_active',
        'authority',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function calculationTypeOptions(): array
    {
        return [
            self::CalculationFixed => 'Fixed amount',
            self::CalculationPerUnit => 'Per-unit peso amount',
            self::CalculationManual => 'Manual charge',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ledgerCategoryOptions(): array
    {
        return [
            self::LedgerCategoryCharge => 'Charge',
            self::LedgerCategoryDownpayment => 'Downpayment requirement',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function displayCategoryOptions(): array
    {
        return [
            self::DisplayCategoryTuition => 'Tuition Fee',
            self::DisplayCategoryLaboratory => 'Laboratory Fee',
            self::DisplayCategoryMiscellaneous => 'Miscellaneous Fee',
            self::DisplayCategoryOther => 'Other Fee',
            self::DisplayCategoryRegistration => 'Registration Fee',
            self::DisplayCategoryDownpayment => 'Downpayment',
        ];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return HasMany<AssessmentLine, $this> */
    public function assessmentLines(): HasMany
    {
        return $this->hasMany(AssessmentLine::class);
    }
}
