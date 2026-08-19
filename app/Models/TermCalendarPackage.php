<?php

namespace App\Models;

use Database\Factories\TermCalendarPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TermCalendarPackage extends Model
{
    /** @use HasFactory<TermCalendarPackageFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StateActive = 'Active';

    public const StateClosed = 'Closed';

    protected $fillable = [
        'term_id', 'version', 'state', 'administrative_starts_on', 'administrative_ends_on',
        'classes_start_on', 'classes_end_on', 'authority_reference', 'authority_date',
        'special_term_schedule_basis', 'recorded_by', 'activated_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'administrative_starts_on' => 'date',
            'administrative_ends_on' => 'date',
            'classes_start_on' => 'date',
            'classes_end_on' => 'date',
            'authority_date' => 'date',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return HasMany<TermCalendarWindow, $this> */
    public function windows(): HasMany
    {
        return $this->hasMany(TermCalendarWindow::class);
    }

    /** @return HasMany<TermTeachingGridRow, $this> */
    public function teachingGridRows(): HasMany
    {
        return $this->hasMany(TermTeachingGridRow::class);
    }

    /** @return HasMany<TermDatedException, $this> */
    public function datedExceptions(): HasMany
    {
        return $this->hasMany(TermDatedException::class);
    }
}
