<?php

namespace App\Models;

use Database\Factories\SchedulingCommitmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingCommitment extends Model
{
    /** @use HasFactory<SchedulingCommitmentFactory> */
    use HasFactory;

    protected $fillable = [
        'term_id', 'section_id', 'faculty_user_id', 'room_id', 'day_of_week',
        'starts_at', 'ends_at', 'authority_reference', 'reason', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function classOffering(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
