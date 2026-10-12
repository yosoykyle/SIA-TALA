<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyAvailabilityDeadline extends Model
{
    protected $fillable = ['term_id', 'due_at', 'authority_reference', 'correction_reason', 'recorded_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
