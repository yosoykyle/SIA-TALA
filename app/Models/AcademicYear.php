<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateClosed = 'CLOSED';

    public const StateArchived = 'ARCHIVED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'starts_on',
        'ends_on',
        'state',
        'is_operational',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_operational' => 'boolean',
        ];
    }

    /**
     * Scope to operational Academic Years managed in Term Planning (excluding historical intake-only batches).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('is_operational', true);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    public static function normalizeLabelForComparison(string $label): string
    {
        $normalized = Str::of($label)
            ->trim()
            ->replace(['–', '—', '−'], '-')
            ->upper()
            ->toString();

        $normalized = (string) preg_replace('/^(?:ACADEMIC\s+YEAR|SCHOOL\s+YEAR|S\.?\s*Y\.?|A\.?\s*Y\.?)\s*[:.-]?\s*/', '', $normalized);
        $normalized = (string) preg_replace('/\s*-\s*/', '-', $normalized);
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    public static function duplicateLabelError(string $label, ?int $ignoreAcademicYearId = null): ?string
    {
        $target = self::normalizeLabelForComparison($label);

        if ($target === '') {
            return null;
        }

        $duplicate = self::query()
            ->when($ignoreAcademicYearId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreAcademicYearId))
            ->get(['id', 'label'])
            ->first(fn (self $existing): bool => self::normalizeLabelForComparison((string) $existing->label) === $target);

        if (! $duplicate instanceof self) {
            return null;
        }

        return sprintf(
            'An academic year matching "%s" (%s) already exists. Use the existing record instead of creating a duplicate.',
            trim($label),
            $duplicate->label,
        );
    }

    public static function overlappingDateError(string $startsOn, string $endsOn, ?int $ignoreAcademicYearId = null): ?string
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();

        if ($end->lte($start)) {
            return null;
        }

        $overlapping = self::query()
            ->where('state', '!=', self::StateArchived)
            ->when($ignoreAcademicYearId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreAcademicYearId))
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->orderBy('starts_on')
            ->first();

        if (! $overlapping instanceof self) {
            return null;
        }

        return sprintf(
            'These dates overlap with %s (%s to %s). Adjust the start or end date so academic years do not overlap.',
            $overlapping->label,
            $overlapping->starts_on->format('M j, Y'),
            $overlapping->ends_on->format('M j, Y'),
        );
    }

    public static function pastOperationalEndDateError(string $endsOn, bool $isOperational = true, ?CarbonImmutable $asOf = null): ?string
    {
        if (! $isOperational) {
            return null;
        }

        $today = ($asOf ?? CarbonImmutable::today(config('app.display_timezone', 'Asia/Manila')))->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();

        if ($end->lt($today)) {
            return sprintf(
                'Operational academic years cannot end in the past (before %s). For prior continuing student batches, add a historical intake batch under Programs & Curricula.',
                $today->format('M j, Y'),
            );
        }

        return null;
    }

    public static function resolveCurrentOperational(?CarbonImmutable $asOf = null): ?self
    {
        $today = ($asOf ?? CarbonImmutable::today(config('app.display_timezone', 'Asia/Manila')))->toDateString();

        $containingToday = self::query()
            ->operational()
            ->where('state', '!=', self::StateArchived)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->orderByRaw('CASE WHEN state = ? THEN 0 WHEN state = ? THEN 1 ELSE 2 END', [self::StateActive, self::StateDraft])
            ->orderBy('starts_on')
            ->orderByDesc('id')
            ->first();

        if ($containingToday instanceof self) {
            return $containingToday;
        }

        $nearestUpcomingActive = self::query()
            ->operational()
            ->where('state', self::StateActive)
            ->whereDate('starts_on', '>', $today)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();

        if ($nearestUpcomingActive instanceof self) {
            return $nearestUpcomingActive;
        }

        $latestActive = self::query()
            ->operational()
            ->where('state', self::StateActive)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        if ($latestActive instanceof self) {
            return $latestActive;
        }

        return self::query()
            ->operational()
            ->where('state', '!=', self::StateArchived)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first()
            ?? self::query()
                ->where('state', '!=', self::StateArchived)
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->first();
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateClosed => 'Closed',
            self::StateArchived => 'Archived',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return self::stateOptions();
    }

    public function displayLabel(): string
    {
        return collect([
            $this->label,
            $this->stateLabel(),
        ])->filter()->implode(' | ');
    }

    public function stateLabel(): string
    {
        return self::stateOptions()[$this->state]
            ?? Str::of((string) $this->state)->headline()->toString();
    }

    public function statusLabel(): string
    {
        return $this->stateLabel();
    }
}
