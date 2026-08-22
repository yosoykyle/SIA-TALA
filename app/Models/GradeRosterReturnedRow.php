<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeRosterReturnedRow extends Model
{
    protected $fillable = [
        'grade_roster_version_id', 'grade_roster_row_id', 'reason', 'returned_by',
        'returned_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GradeRosterVersion::class, 'grade_roster_version_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(GradeRosterRow::class, 'grade_roster_row_id');
    }
}
