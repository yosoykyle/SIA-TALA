<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPolicyMilestone extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'installment_policy_id',
        'sequence',
        'month_offset',
        'required_percentage',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'month_offset' => 'integer',
            'required_percentage' => 'decimal:2',
        ];
    }

    public function installmentPolicy(): BelongsTo
    {
        return $this->belongsTo(InstallmentPolicy::class);
    }
}
