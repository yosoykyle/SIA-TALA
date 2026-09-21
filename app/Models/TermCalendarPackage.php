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
        'classes_start_on', 'classes_end_on', 'faculty_availability_due_at', 'authority_reference', 'authority_date',
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
            'faculty_availability_due_at' => 'datetime',
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

    public function concurrencyToken(): string
    {
        $this->loadMissing(['windows', 'teachingGridRows', 'datedExceptions']);

        $payload = [
            'id' => $this->id,
            'updated_at' => (string) $this->updated_at?->getTimestamp(),
            'recorded_by' => $this->recorded_by,
            'authority_reference' => $this->authority_reference,
            'authority_date' => $this->authority_date?->toDateString(),
            'administrative_starts_on' => $this->administrative_starts_on?->toDateString(),
            'administrative_ends_on' => $this->administrative_ends_on?->toDateString(),
            'classes_start_on' => $this->classes_start_on?->toDateString(),
            'classes_end_on' => $this->classes_end_on?->toDateString(),
            'faculty_availability_due_at' => $this->faculty_availability_due_at?->toIso8601String(),
            'special_term_schedule_basis' => $this->special_term_schedule_basis,
            'windows' => $this->windows->sortBy('id')->map(fn ($w): array => [
                'id' => $w->id,
                'window_type' => $w->window_type,
                'opens_on' => $w->opens_on?->toDateString(),
                'closes_on' => $w->closes_on?->toDateString(),
                'cutoff_at' => (string) $w->cutoff_at,
            ])->values()->all(),
            'teaching_grid_rows' => $this->teachingGridRows->sortBy('id')->map(fn ($r): array => [
                'id' => $r->id,
                'day_of_week' => $r->day_of_week,
                'starts_at' => (string) $r->starts_at,
                'ends_at' => (string) $r->ends_at,
                'breaks' => $r->breaks,
            ])->values()->all(),
            'dated_exceptions' => $this->datedExceptions->sortBy('id')->map(fn ($e): array => [
                'id' => $e->id,
                'starts_on' => $e->starts_on?->toDateString(),
                'ends_on' => $e->ends_on?->toDateString(),
                'exception_type' => $e->exception_type,
                'label' => $e->label,
                'blocks_teaching' => (bool) $e->blocks_teaching,
                'authority_reference' => $e->authority_reference,
            ])->values()->all(),
        ];

        return hash('sha256', (string) json_encode($payload));
    }
}
