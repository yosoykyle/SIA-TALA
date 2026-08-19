<?php

namespace App\Models;

use Database\Factories\TermCalendarWindowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermCalendarWindow extends Model
{
    /** @use HasFactory<TermCalendarWindowFactory> */
    use HasFactory;

    protected $fillable = ['term_calendar_package_id', 'window_type', 'opens_on', 'closes_on', 'cutoff_at'];

    protected function casts(): array
    {
        return ['opens_on' => 'date', 'closes_on' => 'date'];
    }

    /** @return BelongsTo<TermCalendarPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TermCalendarPackage::class, 'term_calendar_package_id');
    }
}
