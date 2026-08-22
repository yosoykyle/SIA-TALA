<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermAverageLabel extends Model
{
    protected $fillable = [
        'term_id', 'label', 'authority_reference', 'authority_date', 'recorded_by', 'recorded_at', 'is_current',
    ];

    protected function casts(): array
    {
        return ['authority_date' => 'date', 'recorded_at' => 'datetime', 'is_current' => 'boolean'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
