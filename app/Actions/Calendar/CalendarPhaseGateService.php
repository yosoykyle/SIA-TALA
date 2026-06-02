<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use stdClass;
use Throwable;

class CalendarPhaseGateService
{
    public function assertEnrollmentWindowOpen(int $termId, string $educationLevel, ?CarbonImmutable $at = null): void
    {
        $now = $at ?? CarbonImmutable::now();
        $term = $this->getTermContext($termId);
        $this->assertEducationLevelMatchesTerm($educationLevel, $term, 'enrollment_window');

        if (! $this->isCutoverActiveForTerm($term, $educationLevel, $now)) {
            return;
        }

        $enrollmentStartsAt = $this->parseTermTimestamp($term->enrollment_starts_at);
        $enrollmentEndsAt = $this->parseTermTimestamp($term->enrollment_ends_at);

        if ($enrollmentStartsAt === null || $enrollmentEndsAt === null) {
            throw new CalendarGateViolation(
                'Enrollment gate is not configured for this term.',
                'enrollment_window',
                ['term_id' => $termId, 'education_level' => $educationLevel],
            );
        }

        if ($now->lt($enrollmentStartsAt) || $now->gt($enrollmentEndsAt)) {
            throw new CalendarGateViolation(
                'Enrollment is outside the configured window.',
                'enrollment_window',
                [
                    'term_id' => $termId,
                    'education_level' => $educationLevel,
                    'enrollment_starts_at' => $enrollmentStartsAt->toIso8601String(),
                    'enrollment_ends_at' => $enrollmentEndsAt->toIso8601String(),
                    'evaluated_at' => $now->toIso8601String(),
                ],
            );
        }
    }

    public function assertSchedulingWindowOpen(int $termId, string $educationLevel, ?CarbonImmutable $at = null): void
    {
        $now = $at ?? CarbonImmutable::now();
        $term = $this->getTermContext($termId);
        $this->assertEducationLevelMatchesTerm($educationLevel, $term, 'scheduling_window');

        if (! $this->isCutoverActiveForTerm($term, $educationLevel, $now)) {
            return;
        }

        $schedulingStartsAt = $this->parseTermTimestamp($term->scheduling_starts_at);

        if ($schedulingStartsAt === null) {
            throw new CalendarGateViolation(
                'Scheduling gate is not configured for this term.',
                'scheduling_window',
                ['term_id' => $termId, 'education_level' => $educationLevel],
            );
        }

        if ($now->lt($schedulingStartsAt)) {
            throw new CalendarGateViolation(
                'Scheduling is not open yet for this term.',
                'scheduling_window',
                [
                    'term_id' => $termId,
                    'education_level' => $educationLevel,
                    'scheduling_starts_at' => $schedulingStartsAt->toIso8601String(),
                    'evaluated_at' => $now->toIso8601String(),
                ],
            );
        }
    }

    public function assertEnrollmentEditWindowOpen(int $termId, string $educationLevel, ?CarbonImmutable $at = null): void
    {
        $now = $at ?? CarbonImmutable::now();
        $term = $this->getTermContext($termId);
        $this->assertEducationLevelMatchesTerm($educationLevel, $term, 'enrollment_edit_window');

        if (! $this->isCutoverActiveForTerm($term, $educationLevel, $now)) {
            return;
        }

        $enrollmentStartsAt = $this->parseTermTimestamp($term->enrollment_starts_at);
        $enrollmentEndsAt = $this->parseTermTimestamp($term->enrollment_ends_at);

        if ($enrollmentStartsAt === null || $enrollmentEndsAt === null) {
            $this->recordEnrollmentEditGateBlock($termId, $educationLevel, $now, 'missing_enrollment_window');

            throw new CalendarGateViolation(
                'Enrollment edit window is not configured for this term.',
                'enrollment_edit_window',
                ['term_id' => $termId, 'education_level' => $educationLevel],
            );
        }

        if ($now->lt($enrollmentStartsAt) || $now->gt($enrollmentEndsAt)) {
            $this->recordEnrollmentEditGateBlock($termId, $educationLevel, $now, 'outside_enrollment_window');

            throw new CalendarGateViolation(
                'Enrollment edits are locked outside the enrollment window.',
                'enrollment_edit_window',
                [
                    'term_id' => $termId,
                    'education_level' => $educationLevel,
                    'enrollment_starts_at' => $enrollmentStartsAt->toIso8601String(),
                    'enrollment_ends_at' => $enrollmentEndsAt->toIso8601String(),
                    'evaluated_at' => $now->toIso8601String(),
                ],
            );
        }
    }

    public function isCutoverActive(int $termId, string $educationLevel, ?CarbonImmutable $at = null): bool
    {
        $now = $at ?? CarbonImmutable::now();
        $term = $this->getTermContext($termId);
        $this->assertEducationLevelMatchesTerm($educationLevel, $term, 'cutover_check');

        return $this->isCutoverActiveForTerm($term, $educationLevel, $now);
    }

    private function isCutoverActiveForTerm(stdClass $term, string $educationLevel, CarbonImmutable $now): bool
    {
        $keys = $this->resolveCutoverKeys($educationLevel);
        $cutoverTerm = $this->getSystemSetting($keys['term_key']);
        $cutoverDatetime = $this->parseSettingTimestamp(
            $this->getSystemSetting($keys['datetime_key']),
            $keys['datetime_key'],
        );

        if ($cutoverTerm === null || $cutoverDatetime === null) {
            return false;
        }

        if ($now->lt($cutoverDatetime)) {
            return false;
        }

        $termStart = CarbonImmutable::parse($term->term_start_date, config('app.timezone'));

        if ($term->term_name === $cutoverTerm) {
            return true;
        }

        return $termStart->greaterThanOrEqualTo($cutoverDatetime->startOfDay());
    }

    /**
     * @return array{term_key: string, datetime_key: string}
     */
    private function resolveCutoverKeys(string $educationLevel): array
    {
        $normalized = strtolower(trim($educationLevel));

        return match ($normalized) {
            'shs' => [
                'term_key' => 'shs_cutover_effective_term',
                'datetime_key' => 'shs_cutover_effective_datetime',
            ],
            'college' => [
                'term_key' => 'college_cutover_effective_term',
                'datetime_key' => 'college_cutover_effective_datetime',
            ],
            default => throw new InvalidArgumentException("Unsupported education level [{$educationLevel}] for cutover checks."),
        };
    }

    private function getSystemSetting(string $key): ?string
    {
        $value = DB::table('system_settings')
            ->where('key', $key)
            ->value('value');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function parseSettingTimestamp(?string $value, string $settingKey): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            Log::warning('Invalid system setting datetime encountered.', [
                'setting_key' => $settingKey,
                'value' => $value,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function parseTermTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, config('app.timezone'));
    }

    private function getTermContext(int $termId): stdClass
    {
        $term = DB::table('terms')
            ->leftJoin('academic_years', 'academic_years.id', '=', 'terms.academic_year_id')
            ->where('terms.id', $termId)
            ->select([
                'terms.id',
                'terms.term_name',
                'terms.term_start_date',
                'terms.enrollment_starts_at',
                'terms.enrollment_ends_at',
                'terms.scheduling_starts_at',
                'academic_years.education_level as term_education_level',
            ])
            ->first();

        if (! $term instanceof stdClass) {
            throw new CalendarGateViolation(
                'Term not found for gate validation.',
                'term_resolution',
                ['term_id' => $termId],
            );
        }

        return $term;
    }

    private function assertEducationLevelMatchesTerm(string $educationLevel, stdClass $term, string $gate): void
    {
        if (! isset($term->term_education_level) || $term->term_education_level === null) {
            return;
        }

        if (strtolower($term->term_education_level) === strtolower($educationLevel)) {
            return;
        }

        throw new CalendarGateViolation(
            'Term education level does not match the requested gate level.',
            $gate,
            [
                'expected_level' => $term->term_education_level,
                'requested_level' => $educationLevel,
                'term_id' => $term->id,
            ],
        );
    }

    private function recordEnrollmentEditGateBlock(
        int $termId,
        string $educationLevel,
        CarbonImmutable $evaluatedAt,
        string $reason
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'calendar_gate',
            'description' => 'Enrollment edit blocked by calendar phase gate.',
            'event' => 'enrollment_edit_blocked',
            'subject_type' => 'term',
            'subject_id' => $termId,
            'causer_type' => Auth::id() !== null ? 'App\\Models\\User' : null,
            'causer_id' => Auth::id(),
            'properties' => json_encode([
                'education_level' => strtolower($educationLevel),
                'reason' => $reason,
                'evaluated_at' => $evaluatedAt->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $evaluatedAt->toDateTimeString(),
            'updated_at' => $evaluatedAt->toDateTimeString(),
        ]);
    }
}
