<?php

namespace App\Models;

use Database\Factories\TermDatedExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermDatedException extends Model
{
    /** @use HasFactory<TermDatedExceptionFactory> */
    use HasFactory;

    protected $fillable = [
        'term_calendar_package_id', 'starts_on', 'ends_on', 'exception_type',
        'label', 'blocks_teaching', 'authority_reference',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'blocks_teaching' => 'boolean'];
    }

    /** @return BelongsTo<TermCalendarPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TermCalendarPackage::class, 'term_calendar_package_id');
    }
}
