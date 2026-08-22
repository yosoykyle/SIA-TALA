<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeRosterVersionRow extends Model
{
    protected $fillable = [
        'grade_roster_version_id', 'grade_roster_row_id', 'course_enrollment_id',
        'final_result', 'inc_completion_note', 'row_revision',
    ];

    protected function casts(): array
    {
        return ['row_revision' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GradeRosterVersion::class, 'grade_roster_version_id');
    }

    public function rosterRow(): BelongsTo
    {
        return $this->belongsTo(GradeRosterRow::class, 'grade_roster_row_id');
    }
}
