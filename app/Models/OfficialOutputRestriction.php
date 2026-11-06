<?php

namespace App\Models;

use Database\Factories\OfficialOutputRestrictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficialOutputRestriction extends Model
{
    /** @use HasFactory<OfficialOutputRestrictionFactory> */
    use HasFactory;

    public const OutputTor = 'TALA_STANDARD_TOR';

    protected $fillable = ['student_profile_id', 'output_type', 'reason', 'source_reference', 'recorded_by', 'recorded_at', 'resolved_by', 'resolved_at', 'resolution_reason'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
