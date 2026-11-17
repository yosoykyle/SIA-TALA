<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Models\TermCalendarPackage;
use App\Models\TermDatedException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CashierSchedule
{
    public const StatusClosed = 'closed';

    public const StatusCustomHours = 'custom_hours';

    /**
     * @var array<int, string>
     */
    private const ShortDayNames = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    /**
     * @var array<int, string>
     */
    private const FullDayNames = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public static function defaultTimezone(): string
    {
        $configured = trim((string) config('app.display_timezone', 'Asia/Manila'));

        return $configured !== '' && in_array($configured, timezone_identifiers_list(), true)
            ? $configured
            : 'Asia/Manila';
    }

    /**
     * @return array{
     *     timezone: string,
     *     cutoff_note: ?string,
     *     weekly: array<int, array{open: bool, opens_at: ?string, closes_at: ?string}>,
     *     exceptions: list<array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}>
     * }
     */
    public static function defaultSchedule(): array
    {
        $weekly = [];

        for ($day = 1; $day <= 7; $day++) {
            $isWeekday = $day <= 5;
            $weekly[$day] = [
                'open' => $isWeekday,
                'opens_at' => '08:00',
                'closes_at' => $isWeekday ? '17:00' : '12:00',
            ];
        }

        return [
            'timezone' => self::defaultTimezone(),
            'cutoff_note' => null,
            'weekly' => $weekly,
            'exceptions' => [],
        ];
    }

    /**
     * @return array{
     *     timezone: string,
     *     cutoff_note: ?string,
     *     weekly: array<int, array{open: bool, opens_at: ?string, closes_at: ?string}>,
     *     exceptions: list<array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}>
     * }
     */
    public static function normalize(mixed $input): array
    {
        $defaults = self::defaultSchedule();

        if (! is_array($input)) {
            return $defaults;
        }

        $rawTimezone = self::sanitizeNullableString($input['timezone'] ?? null);
        $timezone = $rawTimezone !== null && in_array($rawTimezone, timezone_identifiers_list(), true)
            ? $rawTimezone
            : $defaults['timezone'];

        $cutoffNote = self::sanitizeNullableString($input['cutoff_note'] ?? null);

        $weeklyInput = is_array($input['weekly'] ?? null) ? $input['weekly'] : null;
        $weekly = [];

        for ($day = 1; $day <= 7; $day++) {
            $defaultDay = $defaults['weekly'][$day];

            if ($weeklyInput === null) {
                $weekly[$day] = $defaultDay;

                continue;
            }

            $rawDay = $weeklyInput[$day] ?? $weeklyInput[(string) $day] ?? null;

            if (! is_array($rawDay)) {
                $weekly[$day] = $defaultDay;

                continue;
            }

            $open = filter_var($rawDay['open'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $opensAt = array_key_exists('opens_at', $rawDay)
                ? self::normalizeTimeString($rawDay['opens_at'])
                : ($open ? null : $defaultDay['opens_at']);
            $closesAt = array_key_exists('closes_at', $rawDay)
                ? self::normalizeTimeString($rawDay['closes_at'])
                : ($open ? null : $defaultDay['closes_at']);

            $weekly[$day] = [
                'open' => $open,
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
            ];
        }

        $exceptions = [];
        $rawExceptions = is_array($input['exceptions'] ?? null) ? $input['exceptions'] : [];

        foreach ($rawExceptions as $rawException) {
            if (! is_array($rawException)) {
                continue;
            }

            $exceptions[] = self::normalizeException($rawException);
        }

        return [
            'timezone' => $timezone,
            'cutoff_note' => $cutoffNote,
            'weekly' => $weekly,
            'exceptions' => $exceptions,
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{
     *     timezone: string,
     *     cutoff_note: ?string,
     *     weekly: array<int, array{open: bool, opens_at: ?string, closes_at: ?string}>,
     *     exceptions: list<array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}>
     * }
     *
     * @throws ValidationException
     */
    public static function validate(array $schedule): array
    {
        $normalized = self::normalize($schedule);
        $errors = [];

        if ($normalized['cutoff_note'] !== null && mb_strlen($normalized['cutoff_note']) > 255) {
            $errors['cash.schedule.cutoff_note'][] = 'Cutoff note must not exceed 255 characters.';
        }

        for ($day = 1; $day <= 7; $day++) {
            $daySchedule = $normalized['weekly'][$day];
            $dayName = self::FullDayNames[$day];
            $opensAt = $daySchedule['opens_at'];
            $closesAt = $daySchedule['closes_at'];

            if ($daySchedule['open']) {
                $opensValid = self::isValidTime($opensAt);
                $closesValid = self::isValidTime($closesAt);

                if ($opensAt === null) {
                    $errors["cash.schedule.weekly.{$day}.opens_at"][] = "{$dayName} opening time is required when open.";
                } elseif (! $opensValid) {
                    $errors["cash.schedule.weekly.{$day}.opens_at"][] = "{$dayName} opening time must be a valid HH:MM time.";
                }

                if ($closesAt === null) {
                    $errors["cash.schedule.weekly.{$day}.closes_at"][] = "{$dayName} closing time is required when open.";
                } elseif (! $closesValid) {
                    $errors["cash.schedule.weekly.{$day}.closes_at"][] = "{$dayName} closing time must be a valid HH:MM time.";
                }

                if ($opensValid && $closesValid && strcmp((string) $closesAt, (string) $opensAt) <= 0) {
                    $errors["cash.schedule.weekly.{$day}.closes_at"][] = "{$dayName} closing time must be after opening time.";
                }
            } else {
                if ($opensAt !== null && ! self::isValidTime($opensAt)) {
                    $errors["cash.schedule.weekly.{$day}.opens_at"][] = "{$dayName} opening time must be a valid HH:MM time.";
                }

                if ($closesAt !== null && ! self::isValidTime($closesAt)) {
                    $errors["cash.schedule.weekly.{$day}.closes_at"][] = "{$dayName} closing time must be a valid HH:MM time.";
                }
            }
        }

        foreach ($normalized['exceptions'] as $index => $exception) {
            $startsOn = $exception['starts_on'];
            $endsOn = $exception['ends_on'];
            $status = $exception['status'];
            $label = $exception['label'];

            $startsValid = self::isValidDate($startsOn);
            $endsValid = self::isValidDate($endsOn);

            if ($startsOn === null) {
                $errors["cash.schedule.exceptions.{$index}.starts_on"][] = 'Start date is required.';
            } elseif (! $startsValid) {
                $errors["cash.schedule.exceptions.{$index}.starts_on"][] = 'Start date must be a valid YYYY-MM-DD date.';
            }

            if ($endsOn === null) {
                $errors["cash.schedule.exceptions.{$index}.ends_on"][] = 'End date is required.';
            } elseif (! $endsValid) {
                $errors["cash.schedule.exceptions.{$index}.ends_on"][] = 'End date must be a valid YYYY-MM-DD date.';
            }

            if ($startsValid && $endsValid && strcmp((string) $endsOn, (string) $startsOn) < 0) {
                $errors["cash.schedule.exceptions.{$index}.ends_on"][] = 'End date must be on or after the start date.';
            }

            if (! in_array($status, [self::StatusClosed, self::StatusCustomHours], true)) {
                $errors["cash.schedule.exceptions.{$index}.status"][] = 'Exception status must be closed or custom_hours.';
            }

            if ($label === null) {
                $errors["cash.schedule.exceptions.{$index}.label"][] = 'Exception label is required.';
            } elseif (mb_strlen($label) > 160) {
                $errors["cash.schedule.exceptions.{$index}.label"][] = 'Exception label must not exceed 160 characters.';
            }

            if ($status === self::StatusCustomHours) {
                $opensAt = $exception['opens_at'];
                $closesAt = $exception['closes_at'];
                $opensValid = self::isValidTime($opensAt);
                $closesValid = self::isValidTime($closesAt);

                if ($opensAt === null) {
                    $errors["cash.schedule.exceptions.{$index}.opens_at"][] = 'Opening time is required for special hours.';
                } elseif (! $opensValid) {
                    $errors["cash.schedule.exceptions.{$index}.opens_at"][] = 'Opening time must be a valid HH:MM time.';
                }

                if ($closesAt === null) {
                    $errors["cash.schedule.exceptions.{$index}.closes_at"][] = 'Closing time is required for special hours.';
                } elseif (! $closesValid) {
                    $errors["cash.schedule.exceptions.{$index}.closes_at"][] = 'Closing time must be a valid HH:MM time.';
                }

                if ($opensValid && $closesValid && strcmp((string) $closesAt, (string) $opensAt) <= 0) {
                    $errors["cash.schedule.exceptions.{$index}.closes_at"][] = 'Closing time must be after opening time.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    public static function formatHoursSummary(array $schedule): string
    {
        $normalized = self::normalize($schedule);
        $groups = self::groupWeeklyDays($normalized, includeClosed: false);

        if ($groups === []) {
            return 'By appointment / check calendar';
        }

        $segments = [];

        foreach ($groups as $group) {
            $segments[] = $group['days'].', '.$group['hours'];
        }

        return implode(' · ', $segments);
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return list<array{days: string, hours: string}>
     */
    public static function weeklySummaryRows(array $schedule): array
    {
        $normalized = self::normalize($schedule);

        return self::groupWeeklyDays($normalized, includeClosed: true);
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{
     *     status: string,
     *     badge: string,
     *     tone: string,
     *     detail: string,
     *     hours_label: string,
     *     exception_label: ?string,
     *     date: string,
     *     timezone: string
     * }
     */
    public static function todayStatus(array $schedule, ?CarbonImmutable $now = null): array
    {
        $normalized = self::normalize($schedule);
        $timezone = $normalized['timezone'];
        $localNow = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $today = $localNow->startOfDay();
        $resolution = self::resolveDateSchedule($normalized, $today);
        $nextOpen = self::findNextOpenDay($normalized, $today);
        $nextOpenDetail = $nextOpen !== null
            ? self::formatNextOpenDetail($today, $nextOpen['date'], $nextOpen['opens_at'])
            : 'No upcoming open hours scheduled';

        if ($resolution['state'] === 'holiday') {
            $exceptionLabel = $resolution['label'] ?? 'Holiday';
            $detail = $nextOpen !== null
                ? "{$exceptionLabel} · {$nextOpenDetail}"
                : $exceptionLabel;

            return [
                'status' => 'closed_today',
                'badge' => 'Closed today',
                'tone' => 'gray',
                'detail' => $detail,
                'hours_label' => 'Closed',
                'exception_label' => $exceptionLabel,
                'date' => $today->toDateString(),
                'timezone' => $timezone,
            ];
        }

        if ($resolution['state'] === 'closed') {
            return [
                'status' => 'closed_today',
                'badge' => 'Closed today',
                'tone' => 'gray',
                'detail' => $nextOpen !== null ? $nextOpenDetail : 'By appointment / check calendar',
                'hours_label' => 'Closed',
                'exception_label' => null,
                'date' => $today->toDateString(),
                'timezone' => $timezone,
            ];
        }

        $opensAt = self::combineDateAndTime($today, (string) $resolution['opens_at']);
        $closesAt = self::combineDateAndTime($today, (string) $resolution['closes_at']);
        $opensFormatted = self::formatTime((string) $resolution['opens_at']);
        $closesFormatted = self::formatTime((string) $resolution['closes_at']);

        if ($resolution['state'] === 'custom_hours') {
            $exceptionLabel = $resolution['label'] ?? 'Special hours';

            if ($localNow->lt($opensAt)) {
                return [
                    'status' => 'special_hours',
                    'badge' => 'Special hours today',
                    'tone' => 'warning',
                    'detail' => "{$exceptionLabel} · Opens at {$opensFormatted} ({$resolution['hours_label']})",
                    'hours_label' => $resolution['hours_label'],
                    'exception_label' => $exceptionLabel,
                    'date' => $today->toDateString(),
                    'timezone' => $timezone,
                ];
            }

            if ($localNow->lt($closesAt)) {
                return [
                    'status' => 'special_hours',
                    'badge' => 'Special hours · Open now',
                    'tone' => 'warning',
                    'detail' => "{$exceptionLabel} · Open until {$closesFormatted}",
                    'hours_label' => $resolution['hours_label'],
                    'exception_label' => $exceptionLabel,
                    'date' => $today->toDateString(),
                    'timezone' => $timezone,
                ];
            }

            return [
                'status' => 'closed_today',
                'badge' => 'Closed for today',
                'tone' => 'gray',
                'detail' => "{$exceptionLabel} (closed at {$closesFormatted}) · {$nextOpenDetail}",
                'hours_label' => $resolution['hours_label'],
                'exception_label' => $exceptionLabel,
                'date' => $today->toDateString(),
                'timezone' => $timezone,
            ];
        }

        if ($localNow->lt($opensAt)) {
            return [
                'status' => 'opens_later',
                'badge' => 'Opens later today',
                'tone' => 'warning',
                'detail' => "Opens at {$opensFormatted} · {$resolution['hours_label']}",
                'hours_label' => $resolution['hours_label'],
                'exception_label' => null,
                'date' => $today->toDateString(),
                'timezone' => $timezone,
            ];
        }

        if ($localNow->lt($closesAt)) {
            return [
                'status' => 'open_now',
                'badge' => 'Open now',
                'tone' => 'success',
                'detail' => "Open today until {$closesFormatted}",
                'hours_label' => $resolution['hours_label'],
                'exception_label' => null,
                'date' => $today->toDateString(),
                'timezone' => $timezone,
            ];
        }

        return [
            'status' => 'closed_today',
            'badge' => 'Closed for today',
            'tone' => 'gray',
            'detail' => "Closed at {$closesFormatted} · {$nextOpenDetail}",
            'hours_label' => $resolution['hours_label'],
            'exception_label' => null,
            'date' => $today->toDateString(),
            'timezone' => $timezone,
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return list<array{
     *     starts_on: string,
     *     ends_on: string,
     *     date_label: string,
     *     status: string,
     *     hours_label: ?string,
     *     label: string
     * }>
     */
    public static function upcomingExceptions(array $schedule, ?CarbonImmutable $from = null, int $days = 90): array
    {
        $normalized = self::normalize($schedule);
        $timezone = $normalized['timezone'];
        $startDate = ($from ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $endDate = $startDate->addDays(max(0, $days));
        $startStr = $startDate->toDateString();
        $endStr = $endDate->toDateString();

        $matching = [];

        foreach ($normalized['exceptions'] as $exception) {
            if (! self::isRenderableException($exception)) {
                continue;
            }

            $startsOn = (string) $exception['starts_on'];
            $endsOn = (string) $exception['ends_on'];

            if (strcmp($endsOn, $startStr) < 0 || strcmp($startsOn, $endStr) > 0) {
                continue;
            }

            $matching[] = [
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'date_label' => self::formatDateRangeLabel($startsOn, $endsOn),
                'status' => $exception['status'],
                'hours_label' => $exception['status'] === self::StatusCustomHours
                    ? self::formatTimeRange((string) $exception['opens_at'], (string) $exception['closes_at'])
                    : null,
                'label' => (string) $exception['label'],
            ];
        }

        usort($matching, static function (array $left, array $right): int {
            $startCmp = strcmp($left['starts_on'], $right['starts_on']);

            if ($startCmp !== 0) {
                return $startCmp;
            }

            return strcmp($left['ends_on'], $right['ends_on']);
        });

        return $matching;
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return list<array{
     *     month_key: string,
     *     month_label: string,
     *     year: int,
     *     month: int,
     *     weeks: list<list<array{
     *         date: string,
     *         day: int,
     *         in_month: bool,
     *         is_today: bool,
     *         state: string,
     *         hours_label: string,
     *         label: ?string,
     *         summary: string
     *     }>>
     * }>
     */
    public static function monthCalendars(
        array $schedule,
        ?CarbonImmutable $anchor = null,
        int $monthCount = 3,
        ?CarbonImmutable $today = null,
    ): array {
        $normalized = self::normalize($schedule);
        $timezone = $normalized['timezone'];
        $todayDate = ($today ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $anchorMonth = ($anchor ?? $todayDate)->setTimezone($timezone)->startOfMonth();
        $count = max(1, $monthCount);

        $months = [];

        for ($offset = 0; $offset < $count; $offset++) {
            $monthStart = $anchorMonth->addMonthsNoOverflow($offset);
            $monthEnd = $monthStart->endOfMonth()->startOfDay();
            $gridStart = $monthStart->startOfWeek(CarbonInterface::MONDAY);
            $gridEnd = $monthEnd->endOfWeek(CarbonInterface::SUNDAY);

            $weeks = [];
            $week = [];
            $cursor = $gridStart;

            while ($cursor->lte($gridEnd)) {
                $resolution = self::resolveDateSchedule($normalized, $cursor);
                $inMonth = $cursor->month === $monthStart->month && $cursor->year === $monthStart->year;
                $isToday = $cursor->isSameDay($todayDate);
                $state = $resolution['state'];
                $hoursLabel = $resolution['hours_label'];
                $label = $resolution['label'];

                $week[] = [
                    'date' => $cursor->toDateString(),
                    'day' => $cursor->day,
                    'in_month' => $inMonth,
                    'is_today' => $isToday,
                    'state' => $state,
                    'hours_label' => $hoursLabel,
                    'label' => $label,
                    'summary' => self::formatCellSummary($cursor, $isToday, $state, $hoursLabel, $label),
                ];

                if (count($week) === 7) {
                    $weeks[] = $week;
                    $week = [];
                }

                $cursor = $cursor->addDay();
            }

            $months[] = [
                'month_key' => $monthStart->format('Y-m'),
                'month_label' => $monthStart->format('F Y'),
                'year' => $monthStart->year,
                'month' => $monthStart->month,
                'weeks' => $weeks,
            ];
        }

        return $months;
    }

    /**
     * Format a concise header label for a date-exception repeater item so collapsed items retain full context.
     */
    public static function formatExceptionItemLabel(mixed $raw): string
    {
        $exception = self::normalizeException(is_array($raw) ? $raw : []);

        $dateLabel = null;
        if (self::isValidDate($exception['starts_on'])) {
            $endsOn = self::isValidDate($exception['ends_on']) && strcmp((string) $exception['ends_on'], (string) $exception['starts_on']) >= 0
                ? (string) $exception['ends_on']
                : (string) $exception['starts_on'];

            $dateLabel = self::formatDateRangeLabel((string) $exception['starts_on'], $endsOn);
        }

        $isCustomHours = $exception['status'] === self::StatusCustomHours;
        $statusLabel = $isCustomHours
            && self::isValidTime($exception['opens_at'])
            && self::isValidTime($exception['closes_at'])
            ? self::formatTimeRange((string) $exception['opens_at'], (string) $exception['closes_at'])
            : ($isCustomHours ? 'Special hours' : 'Closed');

        $parts = [];

        if ($exception['label'] !== null) {
            $parts[] = $exception['label'];
        }

        if ($dateLabel !== null) {
            $parts[] = $dateLabel;
        }

        if ($parts === []) {
            $parts[] = 'New date exception';
        }

        $parts[] = $statusLabel;

        return implode(' · ', $parts);
    }

    /**
     * Read-only hints sourced from published/active Registrar TermCalendarPackage records.
     * Cashier availability remains completely independent from Registrar holidays.
     *
     * @param  array<int|string, mixed>  $existingExceptions
     * @return list<array{
     *     id: int,
     *     starts_on: string,
     *     ends_on: string,
     *     date_label: string,
     *     label: string,
     *     exception_type: string,
     *     already_added: bool
     * }>
     */
    public static function registrarHolidayHints(array $existingExceptions = [], ?CarbonImmutable $from = null): array
    {
        try {
            if (! Schema::hasTable('term_dated_exceptions') || ! Schema::hasTable('term_calendar_packages')) {
                return [];
            }

            $timezone = self::defaultTimezone();
            $threshold = ($from ?? CarbonImmutable::now($timezone))
                ->setTimezone($timezone)
                ->startOfDay()
                ->subDays(7)
                ->toDateString();

            $records = TermDatedException::query()
                ->whereHas('package', static function ($query): void {
                    $query->whereIn('state', [TermCalendarPackage::StateActive, 'Published']);
                })
                ->whereDate('ends_on', '>=', $threshold)
                ->orderBy('starts_on')
                ->orderBy('ends_on')
                ->limit(12)
                ->get();
        } catch (Throwable) {
            return [];
        }

        if ($records->isEmpty()) {
            return [];
        }

        $normalizedExisting = [];

        foreach ($existingExceptions as $existing) {
            if (! is_array($existing)) {
                continue;
            }

            $normalized = self::normalizeException($existing);

            if (self::isValidDate($normalized['starts_on']) && self::isValidDate($normalized['ends_on'])) {
                $normalizedExisting[] = $normalized;
            }
        }

        $hints = [];

        foreach ($records as $record) {
            $startsOn = $record->starts_on?->toDateString();
            $endsOn = $record->ends_on?->toDateString() ?? $startsOn;

            if ($startsOn === null || $endsOn === null) {
                continue;
            }

            $label = trim((string) $record->label);
            $alreadyAdded = false;

            foreach ($normalizedExisting as $existing) {
                if ($existing['starts_on'] === $startsOn && $existing['ends_on'] === $endsOn) {
                    $alreadyAdded = true;

                    break;
                }
            }

            $hints[] = [
                'id' => (int) $record->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'date_label' => self::formatDateRangeLabel($startsOn, $endsOn),
                'label' => $label !== '' ? $label : 'Term exception',
                'exception_type' => (string) $record->exception_type,
                'already_added' => $alreadyAdded,
            ];
        }

        return $hints;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}
     */
    private static function normalizeException(array $raw): array
    {
        $startsOn = self::normalizeDateString($raw['starts_on'] ?? null);
        $endsOn = self::normalizeDateString($raw['ends_on'] ?? null);

        if ($endsOn === null && self::isValidDate($startsOn)) {
            $endsOn = $startsOn;
        }

        $rawStatus = self::sanitizeNullableString($raw['status'] ?? null);
        $status = $rawStatus ?? self::StatusClosed;

        if ($status === self::StatusClosed) {
            $opensAt = null;
            $closesAt = null;
        } else {
            $opensAt = self::normalizeTimeString($raw['opens_at'] ?? null);
            $closesAt = self::normalizeTimeString($raw['closes_at'] ?? null);
        }

        return [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => $status,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'label' => self::sanitizeNullableString($raw['label'] ?? null),
        ];
    }

    /**
     * @param  array{
     *     timezone: string,
     *     cutoff_note: ?string,
     *     weekly: array<int, array{open: bool, opens_at: ?string, closes_at: ?string}>,
     *     exceptions: list<array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}>
     * }  $normalized
     * @return list<array{days: string, hours: string}>
     */
    private static function groupWeeklyDays(array $normalized, bool $includeClosed): array
    {
        $groups = [];
        $currentGroup = null;

        for ($day = 1; $day <= 7; $day++) {
            $entry = $normalized['weekly'][$day];
            $isOpen = $entry['open']
                && self::isValidTime($entry['opens_at'])
                && self::isValidTime($entry['closes_at'])
                && strcmp((string) $entry['closes_at'], (string) $entry['opens_at']) > 0;

            if (! $isOpen && ! $includeClosed) {
                if ($currentGroup !== null) {
                    $groups[] = $currentGroup;
                    $currentGroup = null;
                }

                continue;
            }

            $signature = $isOpen
                ? 'open|'.$entry['opens_at'].'|'.$entry['closes_at']
                : 'closed';

            $hoursLabel = $isOpen
                ? self::formatTimeRange((string) $entry['opens_at'], (string) $entry['closes_at'])
                : 'Closed';

            if ($currentGroup !== null && $currentGroup['signature'] === $signature && $currentGroup['end_day'] === $day - 1) {
                $currentGroup['end_day'] = $day;

                continue;
            }

            if ($currentGroup !== null) {
                $groups[] = $currentGroup;
            }

            $currentGroup = [
                'signature' => $signature,
                'start_day' => $day,
                'end_day' => $day,
                'hours' => $hoursLabel,
            ];
        }

        if ($currentGroup !== null) {
            $groups[] = $currentGroup;
        }

        $rows = [];

        foreach ($groups as $group) {
            $daysLabel = $group['start_day'] === $group['end_day']
                ? self::ShortDayNames[$group['start_day']]
                : self::ShortDayNames[$group['start_day']]."\u{2013}".self::ShortDayNames[$group['end_day']];

            $rows[] = [
                'days' => $daysLabel,
                'hours' => $group['hours'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array{
     *     timezone: string,
     *     cutoff_note: ?string,
     *     weekly: array<int, array{open: bool, opens_at: ?string, closes_at: ?string}>,
     *     exceptions: list<array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}>
     * }  $normalized
     * @return array{
     *     state: string,
     *     is_open: bool,
     *     opens_at: ?string,
     *     closes_at: ?string,
     *     hours_label: string,
     *     label: ?string
     * }
     */
    private static function resolveDateSchedule(array $normalized, CarbonImmutable $date): array
    {
        $dateStr = $date->toDateString();
        $customMatch = null;

        foreach ($normalized['exceptions'] as $exception) {
            if (! self::isRenderableException($exception)) {
                continue;
            }

            if (strcmp($dateStr, (string) $exception['starts_on']) < 0 || strcmp($dateStr, (string) $exception['ends_on']) > 0) {
                continue;
            }

            if ($exception['status'] === self::StatusClosed) {
                return [
                    'state' => 'holiday',
                    'is_open' => false,
                    'opens_at' => null,
                    'closes_at' => null,
                    'hours_label' => 'Closed',
                    'label' => $exception['label'],
                ];
            }

            if ($customMatch === null && $exception['status'] === self::StatusCustomHours) {
                $customMatch = $exception;
            }
        }

        if ($customMatch !== null) {
            return [
                'state' => 'custom_hours',
                'is_open' => true,
                'opens_at' => $customMatch['opens_at'],
                'closes_at' => $customMatch['closes_at'],
                'hours_label' => self::formatTimeRange((string) $customMatch['opens_at'], (string) $customMatch['closes_at']),
                'label' => $customMatch['label'],
            ];
        }

        $dayConfig = $normalized['weekly'][$date->dayOfWeekIso] ?? ['open' => false, 'opens_at' => null, 'closes_at' => null];

        if (
            $dayConfig['open']
            && self::isValidTime($dayConfig['opens_at'])
            && self::isValidTime($dayConfig['closes_at'])
            && strcmp((string) $dayConfig['closes_at'], (string) $dayConfig['opens_at']) > 0
        ) {
            return [
                'state' => 'open',
                'is_open' => true,
                'opens_at' => $dayConfig['opens_at'],
                'closes_at' => $dayConfig['closes_at'],
                'hours_label' => self::formatTimeRange((string) $dayConfig['opens_at'], (string) $dayConfig['closes_at']),
                'label' => null,
            ];
        }

        return [
            'state' => 'closed',
            'is_open' => false,
            'opens_at' => null,
            'closes_at' => null,
            'hours_label' => 'Closed',
            'label' => null,
        ];
    }

    /**
     * @param  array{
     *     timezone: string,
     *     cutoff_note: ?string,
     *     weekly: array<int, array{open: bool, opens_at: ?string, closes_at: ?string}>,
     *     exceptions: list<array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}>
     * }  $normalized
     * @return ?array{date: CarbonImmutable, opens_at: string, closes_at: string}
     */
    private static function findNextOpenDay(array $normalized, CarbonImmutable $fromDate, int $lookaheadDays = 30): ?array
    {
        for ($offset = 1; $offset <= $lookaheadDays; $offset++) {
            $candidate = $fromDate->addDays($offset);
            $resolution = self::resolveDateSchedule($normalized, $candidate);

            if ($resolution['is_open'] && $resolution['opens_at'] !== null && $resolution['closes_at'] !== null) {
                return [
                    'date' => $candidate,
                    'opens_at' => $resolution['opens_at'],
                    'closes_at' => $resolution['closes_at'],
                ];
            }
        }

        return null;
    }

    private static function formatNextOpenDetail(CarbonImmutable $today, CarbonImmutable $nextDate, string $opensAt): string
    {
        $opensFormatted = self::formatTime($opensAt);

        if ($nextDate->isSameDay($today->addDay())) {
            return "Opens tomorrow at {$opensFormatted}";
        }

        return 'Next open '.$nextDate->format('D, M j')." at {$opensFormatted}";
    }

    private static function formatCellSummary(
        CarbonImmutable $date,
        bool $isToday,
        string $state,
        string $hoursLabel,
        ?string $label,
    ): string {
        $dateText = $date->format('l, M j, Y').($isToday ? ' (Today)' : '');

        return match ($state) {
            'holiday' => "{$dateText}: Closed".($label !== null ? " ({$label})" : ''),
            'custom_hours' => "{$dateText}: Special hours {$hoursLabel}".($label !== null ? " ({$label})" : ''),
            'open' => "{$dateText}: Open {$hoursLabel}",
            default => "{$dateText}: Closed",
        };
    }

    private static function formatTimeRange(string $opensAt, string $closesAt): string
    {
        return self::formatTime($opensAt)." \u{2013} ".self::formatTime($closesAt);
    }

    private static function formatTime(string $time): string
    {
        $parsed = CarbonImmutable::createFromFormat('!H:i', $time);

        return $parsed instanceof CarbonImmutable ? $parsed->format('g:i A') : $time;
    }

    private static function combineDateAndTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setTime($hour, $minute, 0);
    }

    private static function formatDateRangeLabel(string $startsOn, string $endsOn): string
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endsOn);

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
            return $startsOn === $endsOn ? $startsOn : "{$startsOn} \u{2013} {$endsOn}";
        }

        if ($startsOn === $endsOn) {
            return $start->format('M j, Y');
        }

        if ($start->year === $end->year && $start->month === $end->month) {
            return $start->format('M j')."\u{2013}".$end->format('j, Y');
        }

        if ($start->year === $end->year) {
            return $start->format('M j')."\u{2013}".$end->format('M j, Y');
        }

        return $start->format('M j, Y')."\u{2013}".$end->format('M j, Y');
    }

    /**
     * @param  array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}  $exception
     */
    private static function isRenderableException(array $exception): bool
    {
        if (
            ! self::isValidDate($exception['starts_on'])
            || ! self::isValidDate($exception['ends_on'])
            || strcmp((string) $exception['ends_on'], (string) $exception['starts_on']) < 0
            || $exception['label'] === null
        ) {
            return false;
        }

        if ($exception['status'] === self::StatusClosed) {
            return true;
        }

        if ($exception['status'] === self::StatusCustomHours) {
            return self::isValidTime($exception['opens_at'])
                && self::isValidTime($exception['closes_at'])
                && strcmp((string) $exception['closes_at'], (string) $exception['opens_at']) > 0;
        }

        return false;
    }

    private static function normalizeTimeString(mixed $value): ?string
    {
        $sanitized = self::sanitizeNullableString($value);

        if ($sanitized === null) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', $sanitized, $matches) === 1) {
            $hour = (int) $matches[1];

            if ($hour >= 0 && $hour <= 23) {
                return sprintf('%02d:%s', $hour, $matches[2]);
            }
        }

        return $sanitized;
    }

    private static function normalizeDateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        return self::sanitizeNullableString($value);
    }

    private static function isValidTime(?string $value): bool
    {
        return $value !== null && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function isValidDate(?string $value): bool
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $value;
    }

    private static function sanitizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stripped = strip_tags((string) $value);
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $stripped);
        $trimmed = trim((string) ($cleaned ?? $stripped));

        return $trimmed !== '' ? $trimmed : null;
    }
}
