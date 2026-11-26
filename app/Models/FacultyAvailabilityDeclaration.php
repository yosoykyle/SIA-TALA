<?php

namespace App\Models;

use Database\Factories\FacultyAvailabilityDeclarationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $availability_mode
 * @property array<int, array{day_of_week:int,starts_at:string,ends_at:string}> $hard_unavailability
 * @property array<int, array{day_of_week:int,start_time:string,end_time:string}>|null $teaching_windows
 * @property Carbon $declared_at
 */
class FacultyAvailabilityDeclaration extends Model
{
    /** @use HasFactory<FacultyAvailabilityDeclarationFactory> */
    use HasFactory;

    public const DeclarationAvailable = 'Available';

    public const DeclarationUnavailable = 'Unavailable';

    public const ModeWindows = 'windows';

    public const ModeAnyApproved = 'any_approved';

    public const ModeCannotTeach = 'cannot_teach';

    public const ModeLegacyUnavailability = 'legacy_unavailability';

    protected $fillable = [
        'term_id',
        'faculty_user_id',
        'version',
        'declaration',
        'availability_mode',
        'hard_unavailability',
        'teaching_windows',
        'correction_reason',
        'declared_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'hard_unavailability' => 'array',
            'teaching_windows' => 'array',
            'declared_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function declarationOptions(): array
    {
        return [
            self::DeclarationAvailable => 'Available with the unavailable times below',
            self::DeclarationUnavailable => 'Unavailable for teaching in this Term',
        ];
    }

    /** @return array<string, string> */
    public static function modeOptions(): array
    {
        return [
            self::ModeWindows => 'Choose teaching times',
            self::ModeAnyApproved => 'Any approved teaching time',
            self::ModeCannotTeach => 'Cannot teach this Term',
        ];
    }

    public function effectiveMode(): string
    {
        if (is_string($this->availability_mode)
            && in_array($this->availability_mode, [self::ModeWindows, self::ModeAnyApproved, self::ModeCannotTeach], true)) {
            return $this->availability_mode;
        }

        if ($this->declaration === self::DeclarationUnavailable) {
            return self::ModeCannotTeach;
        }

        if (is_array($this->hard_unavailability) && $this->hard_unavailability !== []) {
            return self::ModeLegacyUnavailability;
        }

        return self::ModeAnyApproved;
    }

    public function modeLabel(): string
    {
        return match ($this->effectiveMode()) {
            self::ModeWindows => 'Choose teaching times',
            self::ModeAnyApproved => 'Any approved teaching time',
            self::ModeCannotTeach => 'Cannot teach this Term',
            self::ModeLegacyUnavailability => 'Any approved teaching time (except recorded unavailable times)',
            default => 'Any approved teaching time',
        };
    }

    /**
     * Identify any stored teaching windows for ModeWindows that fall outside the given active calendar grid
     * or overlap mandatory recurring breaks, without mutating the stored declaration.
     *
     * @return list<array{day_of_week:int,start_time:string,end_time:string,reason:string}>
     */
    public function windowsOutsideGrid(?TermCalendarPackage $calendar): array
    {
        if ($this->effectiveMode() !== self::ModeWindows || ! $calendar instanceof TermCalendarPackage) {
            return [];
        }

        $calendar->loadMissing('teachingGridRows');
        $gridByDay = $calendar->teachingGridRows->keyBy('day_of_week');
        $outside = [];

        foreach ($this->normalizedTeachingWindows() as $window) {
            $day = $window['day_of_week'];
            $winStart = self::clockToMinutes($window['start_time']);
            $winEnd = self::clockToMinutes($window['end_time']);
            $gridRow = $gridByDay->get($day);

            if (! $gridRow instanceof TermTeachingGridRow) {
                $outside[] = [
                    ...$window,
                    'reason' => 'Day is not part of the approved teaching grid.',
                ];

                continue;
            }

            $gridStart = self::clockToMinutes((string) $gridRow->starts_at);
            $gridEnd = self::clockToMinutes((string) $gridRow->ends_at);

            if ($winStart === null || $winEnd === null || $gridStart === null || $gridEnd === null
                || $winStart < $gridStart || $winEnd > $gridEnd) {
                $outside[] = [
                    ...$window,
                    'reason' => 'Chosen teaching window falls outside approved daily teaching hours.',
                ];

                continue;
            }

            $breakIntervals = [];
            foreach (is_array($gridRow->breaks) ? $gridRow->breaks : [] as $break) {
                if (! is_array($break)) {
                    continue;
                }

                $breakStart = self::clockToMinutes((string) ($break['starts_at'] ?? ''));
                $breakEnd = self::clockToMinutes((string) ($break['ends_at'] ?? ''));

                if ($breakStart !== null && $breakEnd !== null && $breakStart < $breakEnd) {
                    $breakIntervals[] = ['start' => $breakStart, 'end' => $breakEnd];
                }
            }

            if ($breakIntervals !== []
                && self::subtractIntervals([['start' => $winStart, 'end' => $winEnd]], $breakIntervals) === []) {
                $outside[] = [
                    ...$window,
                    'reason' => 'Chosen teaching window falls wholly inside a mandatory institutional break.',
                ];
            }
        }

        return $outside;
    }

    public function needsGridReview(?TermCalendarPackage $calendar): bool
    {
        return $this->windowsOutsideGrid($calendar) !== [];
    }

    /**
     * Compile exact recurring unavailable blocks against the supplied approved teaching grid.
     *
     * @return list<array{day_of_week:int,starts_at:string,ends_at:string}>
     */
    public function compileUnavailableBlocks(TermCalendarPackage $calendar): array
    {
        $calendar->loadMissing('teachingGridRows');
        $mode = $this->effectiveMode();

        if ($mode === self::ModeAnyApproved) {
            return [];
        }

        if ($mode === self::ModeLegacyUnavailability) {
            return collect(is_array($this->hard_unavailability) ? $this->hard_unavailability : [])
                ->filter(fn (mixed $interval): bool => is_array($interval))
                ->map(function (array $interval): ?array {
                    $day = (int) ($interval['day_of_week'] ?? 0);
                    $start = self::clockToMinutes((string) ($interval['starts_at'] ?? $interval['start_time'] ?? ''));
                    $end = self::clockToMinutes((string) ($interval['ends_at'] ?? $interval['end_time'] ?? ''));

                    if ($day < 1 || $day > 7 || $start === null || $end === null || $start >= $end) {
                        return null;
                    }

                    return [
                        'day_of_week' => $day,
                        'starts_at' => self::minutesToClock($start),
                        'ends_at' => self::minutesToClock($end),
                    ];
                })
                ->filter()
                ->sortBy(fn (array $block): string => sprintf('%d|%s|%s', $block['day_of_week'], $block['starts_at'], $block['ends_at']))
                ->values()
                ->all();
        }

        $gridRows = $calendar->teachingGridRows->sortBy('day_of_week');

        if ($mode === self::ModeCannotTeach) {
            return $gridRows
                ->map(function (TermTeachingGridRow $row): ?array {
                    $start = self::clockToMinutes((string) $row->starts_at);
                    $end = self::clockToMinutes((string) $row->ends_at);

                    if ($start === null || $end === null || $start >= $end) {
                        return null;
                    }

                    return [
                        'day_of_week' => (int) $row->day_of_week,
                        'starts_at' => self::minutesToClock($start),
                        'ends_at' => self::minutesToClock($end),
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        $windowsByDay = collect($this->normalizedTeachingWindows())->groupBy('day_of_week');
        $blocks = [];

        foreach ($gridRows as $row) {
            $day = (int) $row->day_of_week;
            $gridStart = self::clockToMinutes((string) $row->starts_at);
            $gridEnd = self::clockToMinutes((string) $row->ends_at);

            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $clippedWindows = collect($windowsByDay->get($day, []))
                ->map(function (array $window) use ($gridStart, $gridEnd): ?array {
                    $wStart = self::clockToMinutes($window['start_time']);
                    $wEnd = self::clockToMinutes($window['end_time']);

                    if ($wStart === null || $wEnd === null) {
                        return null;
                    }

                    $clippedStart = max($gridStart, $wStart);
                    $clippedEnd = min($gridEnd, $wEnd);

                    if ($clippedStart >= $clippedEnd) {
                        return null;
                    }

                    return ['start' => $clippedStart, 'end' => $clippedEnd];
                })
                ->filter()
                ->values()
                ->all();

            foreach (self::subtractIntervals([['start' => $gridStart, 'end' => $gridEnd]], $clippedWindows) as $gap) {
                $blocks[] = [
                    'day_of_week' => $day,
                    'starts_at' => self::minutesToClock($gap['start']),
                    'ends_at' => self::minutesToClock($gap['end']),
                ];
            }
        }

        return $blocks;
    }

    public function hasUsableTimeInGrid(TermCalendarPackage $calendar, int $minimumMinutes = 30): bool
    {
        $calendar->loadMissing('teachingGridRows');
        $mode = $this->effectiveMode();

        if ($mode === self::ModeCannotTeach) {
            return false;
        }

        $unavailableByDay = collect($this->compileUnavailableBlocks($calendar))->groupBy('day_of_week');

        foreach ($calendar->teachingGridRows as $row) {
            $day = (int) $row->day_of_week;
            $gridStart = self::clockToMinutes((string) $row->starts_at);
            $gridEnd = self::clockToMinutes((string) $row->ends_at);

            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $cuts = [];

            foreach (is_array($row->breaks) ? $row->breaks : [] as $break) {
                if (! is_array($break)) {
                    continue;
                }

                $bStart = self::clockToMinutes((string) ($break['starts_at'] ?? ''));
                $bEnd = self::clockToMinutes((string) ($break['ends_at'] ?? ''));

                if ($bStart !== null && $bEnd !== null && $bStart < $bEnd) {
                    $cuts[] = ['start' => $bStart, 'end' => $bEnd];
                }
            }

            foreach ($unavailableByDay->get($day, []) as $block) {
                $uStart = self::clockToMinutes((string) ($block['starts_at'] ?? ''));
                $uEnd = self::clockToMinutes((string) ($block['ends_at'] ?? ''));

                if ($uStart !== null && $uEnd !== null && $uStart < $uEnd) {
                    $cuts[] = ['start' => $uStart, 'end' => $uEnd];
                }
            }

            foreach (self::subtractIntervals([['start' => $gridStart, 'end' => $gridEnd]], $cuts) as $usable) {
                if (($usable['end'] - $usable['start']) >= $minimumMinutes) {
                    return true;
                }
            }
        }

        return false;
    }

    public function conflictsWithMeeting(int $dayOfWeek, string $startsAt, string $endsAt): bool
    {
        $meetingStart = self::clockToMinutes($startsAt);
        $meetingEnd = self::clockToMinutes($endsAt);

        if ($meetingStart === null || $meetingEnd === null || $meetingStart >= $meetingEnd) {
            return false;
        }

        $mode = $this->effectiveMode();

        if ($mode === self::ModeCannotTeach) {
            return true;
        }

        if ($mode === self::ModeAnyApproved) {
            return false;
        }

        if ($mode === self::ModeWindows) {
            $dayWindows = collect($this->normalizedTeachingWindows())
                ->where('day_of_week', $dayOfWeek)
                ->map(function (array $window): ?array {
                    $start = self::clockToMinutes($window['start_time']);
                    $end = self::clockToMinutes($window['end_time']);

                    return ($start !== null && $end !== null && $start < $end)
                        ? ['start' => $start, 'end' => $end]
                        : null;
                })
                ->filter()
                ->values()
                ->all();

            foreach (self::mergeIntervals($dayWindows) as $window) {
                if ($window['start'] <= $meetingStart && $window['end'] >= $meetingEnd) {
                    return false;
                }
            }

            return true;
        }

        foreach (is_array($this->hard_unavailability) ? $this->hard_unavailability : [] as $interval) {
            if (! is_array($interval) || (int) ($interval['day_of_week'] ?? 0) !== $dayOfWeek) {
                continue;
            }

            $iStart = self::clockToMinutes((string) ($interval['starts_at'] ?? $interval['start_time'] ?? ''));
            $iEnd = self::clockToMinutes((string) ($interval['ends_at'] ?? $interval['end_time'] ?? ''));

            if ($iStart !== null && $iEnd !== null && $meetingStart < $iEnd && $meetingEnd > $iStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{day_of_week:int,start_time:string,end_time:string}>
     */
    public function normalizedTeachingWindows(): array
    {
        $windows = is_array($this->teaching_windows) ? $this->teaching_windows : [];

        return collect($windows)
            ->filter(fn (mixed $window): bool => is_array($window))
            ->map(function (array $window): ?array {
                $day = (int) ($window['day_of_week'] ?? 0);
                $start = self::clockToMinutes((string) ($window['start_time'] ?? $window['starts_at'] ?? ''));
                $end = self::clockToMinutes((string) ($window['end_time'] ?? $window['ends_at'] ?? ''));

                if ($day < 1 || $day > 7 || $start === null || $end === null || $start >= $end) {
                    return null;
                }

                return [
                    'day_of_week' => $day,
                    'start_time' => self::minutesToHourMinute($start),
                    'end_time' => self::minutesToHourMinute($end),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public static function clockToMinutes(?string $time): ?int
    {
        if ($time === null) {
            return null;
        }

        $trimmed = trim($time);

        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    public static function minutesToClock(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    public static function minutesToHourMinute(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param  list<array{start:int,end:int}>  $intervals
     * @return list<array{start:int,end:int}>
     */
    public static function mergeIntervals(array $intervals): array
    {
        $valid = array_values(array_filter(
            $intervals,
            fn (array $interval): bool => $interval['start'] < $interval['end'],
        ));

        if ($valid === []) {
            return [];
        }

        usort($valid, fn (array $left, array $right): int => [$left['start'], $left['end']] <=> [$right['start'], $right['end']]);

        $merged = [$valid[0]];

        foreach (array_slice($valid, 1) as $interval) {
            $lastIndex = count($merged) - 1;

            if ($interval['start'] <= $merged[$lastIndex]['end']) {
                $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $interval['end']);
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    /**
     * @param  list<array{start:int,end:int}>  $base
     * @param  list<array{start:int,end:int}>  $cuts
     * @return list<array{start:int,end:int}>
     */
    public static function subtractIntervals(array $base, array $cuts): array
    {
        $mergedCuts = self::mergeIntervals($cuts);
        $result = [];

        foreach (self::mergeIntervals($base) as $interval) {
            $cursor = $interval['start'];
            $end = $interval['end'];

            foreach ($mergedCuts as $cut) {
                if ($cut['end'] <= $cursor) {
                    continue;
                }

                if ($cut['start'] >= $end) {
                    break;
                }

                if ($cut['start'] > $cursor) {
                    $result[] = ['start' => $cursor, 'end' => min($cut['start'], $end)];
                }

                $cursor = max($cursor, $cut['end']);

                if ($cursor >= $end) {
                    break;
                }
            }

            if ($cursor < $end) {
                $result[] = ['start' => $cursor, 'end' => $end];
            }
        }

        return $result;
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<User, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }
}
