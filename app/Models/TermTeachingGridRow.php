<?php

namespace App\Models;

use Database\Factories\TermTeachingGridRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermTeachingGridRow extends Model
{
    /** @use HasFactory<TermTeachingGridRowFactory> */
    use HasFactory;

    protected $fillable = ['term_calendar_package_id', 'day_of_week', 'starts_at', 'ends_at', 'breaks'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'breaks' => 'array'];
    }

    /** @return BelongsTo<TermCalendarPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TermCalendarPackage::class, 'term_calendar_package_id');
    }
}
