<?php

namespace App\Models;

use App\Models\Concerns\HasAccountingConfigurationScope;
use Database\Factories\FeeTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeTemplate extends Model
{
    /** @use HasFactory<FeeTemplateFactory> */
    use HasAccountingConfigurationScope, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'education_level',
        'program_id',
        'year_level',
        'tuition_fee',
        'laboratory_fee',
        'misc_fee',
        'other_fee',
        'minimum_downpayment_percentage',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tuition_fee' => 'decimal:2',
            'laboratory_fee' => 'decimal:2',
            'misc_fee' => 'decimal:2',
            'other_fee' => 'decimal:2',
            'minimum_downpayment_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    protected static function activeScopeConflictMessage(): string
    {
        return 'Only one active fee template may exist for the selected education level, program, and year/grade scope.';
    }
}
