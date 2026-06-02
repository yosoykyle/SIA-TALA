<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentPolicy extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'education_level',
        'program_id',
        'year_level',
        'max_months',
        'due_day_rule',
        'grace_days',
        'penalty_rate',
        'penalty_frequency',
        'allow_partial_payments',
        'promissory_is_non_clearing',
        'is_active',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_months' => 'integer',
            'grace_days' => 'integer',
            'penalty_rate' => 'decimal:2',
            'allow_partial_payments' => 'boolean',
            'promissory_is_non_clearing' => 'boolean',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(InstallmentPolicyMilestone::class);
    }
}
