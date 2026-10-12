<?php

namespace App\Models;

use Database\Factories\TermCalendarPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
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

    public function facultyAvailabilityDueAt(): ?Carbon
    {
        return FacultyAvailabilityDeadline::query()->where('term_id', $this->term_id)->latest('id')->first()?->due_at
            ?? $this->faculty_availability_due_at;
    }

    public function coreSignature(): string
    {
        $this->loadMissing(['teachingGridRows', 'datedExceptions']);

        return hash('sha256', (string) json_encode([
            $this->only(['term_id', 'administrative_starts_on', 'administrative_ends_on', 'classes_start_on', 'classes_end_on', 'special_term_schedule_basis']),
            $this->teachingGridRows->sortBy('day_of_week')->map(fn ($row): array => [
                'day_of_week' => $row->day_of_week,
                'starts_at' => $this->normalizedClock((string) $row->starts_at),
                'ends_at' => $this->normalizedClock((string) $row->ends_at),
                'breaks' => collect($row->breaks ?? [])->map(fn ($break): array => [
                    'starts_at' => $this->normalizedClock((string) ($break['starts_at'] ?? '')),
                    'ends_at' => $this->normalizedClock((string) ($break['ends_at'] ?? '')),
                ])->sortBy(fn ($break): string => $break['starts_at'].'|'.$break['ends_at'])->values()->all(),
            ])->values()->all(),
            $this->datedExceptions->map(fn ($exception): array => $exception->only(['starts_on', 'ends_on', 'exception_type', 'label', 'blocks_teaching', 'authority_reference']))->sortBy(fn ($exception): string => (string) json_encode($exception))->values()->all(),
        ]));
    }

    public function isActivatedCoreSource(): bool
    {
        return $this->state === self::StateActive || ($this->state === self::StateClosed && ($this->activated_at !== null || $this->closed_at !== null));
    }

    private function normalizedClock(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);
    }

    /** @return list<int> */
    public function compatibleCoreSourceIds(): array
    {
        $signature = $this->coreSignature();

        return self::query()->where('term_id', $this->term_id)->with(['teachingGridRows', 'datedExceptions'])->get()
            ->filter(fn (self $package): bool => $package->isActivatedCoreSource() && $package->coreSignature() === $signature)
            ->pluck('id')->all();
    }

    public function concurrencyToken(): string
    {
        $this->loadMissing(['windows', 'teachingGridRows', 'datedExceptions']);

        $payload = [
            'id' => $this->id,
            'state' => $this->state,
            'term' => $this->term->only(['id', 'academic_year_id', 'type', 'label', 'starts_on', 'ends_on', 'state']),
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
