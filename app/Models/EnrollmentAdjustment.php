<?php

namespace App\Models;

use Database\Factories\EnrollmentAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $change_snapshot
 */
class EnrollmentAdjustment extends Model
{
    /** @use HasFactory<EnrollmentAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id', 'supersedes_cor_version_id', 'authority_reference', 'financial_effect',
        'change_snapshot', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['change_snapshot' => 'array', 'recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
