<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilityDeclaration;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordFacultyAvailabilityDeclaration
{
    /**
     * @param  list<array{day_of_week: int, starts_at: string, ends_at: string}>  $hardUnavailability
     */
    public function execute(
        Term $term,
        User $faculty,
        User $actor,
        string $declaration,
        array $hardUnavailability,
        ?string $correctionReason = null,
    ): FacultyAvailabilityDeclaration {
        if (! $actor->is($faculty)
            || ! $actor->hasRole(User::StaffRoleFaculty)
            || ! $faculty->hasRole(User::StaffRoleFaculty)) {
            abort(403);
        }

        if (! array_key_exists($declaration, FacultyAvailabilityDeclaration::declarationOptions())) {
            throw ValidationException::withMessages([
                'declaration' => 'Select one of the supported Faculty availability declarations.',
            ]);
        }

        $hardUnavailability = $this->normalizedIntervals($hardUnavailability);

        return DB::transaction(function () use ($term, $faculty, $actor, $declaration, $hardUnavailability, $correctionReason): FacultyAvailabilityDeclaration {
            Term::query()->whereKey($term)->lockForUpdate()->firstOrFail();
            $prior = FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->where('faculty_user_id', $faculty->id)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if ($prior instanceof FacultyAvailabilityDeclaration && blank($correctionReason)) {
                throw ValidationException::withMessages(['correction_reason' => 'A reason is required for a later Faculty availability declaration.']);
            }

            $nextVersion = $prior instanceof FacultyAvailabilityDeclaration
                ? ((int) $prior->version) + 1
                : 1;

            $record = FacultyAvailabilityDeclaration::query()->create([
                'term_id' => $term->id,
                'faculty_user_id' => $faculty->id,
                'version' => $nextVersion,
                'declaration' => $declaration,
                'hard_unavailability' => $hardUnavailability,
                'correction_reason' => $correctionReason,
                'declared_at' => now(),
            ]);

            if ($prior instanceof FacultyAvailabilityDeclaration) {
                ScheduleGenerationRun::query()
                    ->where('term_id', $term->id)
                    ->whereIn('status', [
                        ScheduleGenerationRun::StatusQueued,
                        ScheduleGenerationRun::StatusDispatching,
                        ScheduleGenerationRun::StatusUnderReview,
                    ])
                    ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);

                $this->recordPublishedImpact($term, $faculty, $actor, $record, $hardUnavailability);
            }

            return $record;
        }, attempts: 5);
    }

    /**
     * @param  list<array<string, mixed>>  $intervals
     * @return list<array{day_of_week: int, starts_at: string, ends_at: string}>
     */
    private function normalizedIntervals(array $intervals): array
    {
        $normalized = collect($intervals)
            ->map(function (array $interval): array {
                $day = (int) ($interval['day_of_week'] ?? 0);
                $startsAt = $this->normalizedTime((string) ($interval['starts_at'] ?? ''));
                $endsAt = $this->normalizedTime((string) ($interval['ends_at'] ?? ''));

                if ($day < 1 || $day > 7 || $startsAt === null || $endsAt === null || $startsAt >= $endsAt) {
                    throw ValidationException::withMessages([
                        'hard_unavailability' => 'Every unavailable interval needs a valid day and increasing time bounds.',
                    ]);
                }

                return [
                    'day_of_week' => $day,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ];
            })
            ->unique(fn (array $interval): string => implode('|', $interval))
            ->sortBy(fn (array $interval): string => sprintf(
                '%d|%s|%s',
                $interval['day_of_week'],
                $interval['starts_at'],
                $interval['ends_at'],
            ))
            ->values();

        return $normalized->all();
    }

    private function normalizedTime(string $time): ?string
    {
        $time = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            $time .= ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('H:i:s', $time)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{day_of_week: int, starts_at: string, ends_at: string}>  $intervals
     */
    private function recordPublishedImpact(
        Term $term,
        User $faculty,
        User $actor,
        FacultyAvailabilityDeclaration $declaration,
        array $intervals,
    ): void {
        $affectedMeetingIds = SectionMeeting::query()
            ->activeOfficial()
            ->where('faculty_user_id', $faculty->id)
            ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $term->id))
            ->get(['id', 'day_of_week', 'starts_at', 'ends_at'])
            ->filter(fn (SectionMeeting $meeting): bool => collect($intervals)->contains(
                fn (array $interval): bool => (int) $meeting->day_of_week === $interval['day_of_week']
                    && (string) $meeting->starts_at < $interval['ends_at']
                    && (string) $meeting->ends_at > $interval['starts_at'],
            ))
            ->modelKeys();

        if ($affectedMeetingIds === []) {
            return;
        }

        $timestamp = CarbonImmutable::now(config('app.timezone'));

        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => 'A Faculty availability correction requires published timetable review.',
            'subject_type' => FacultyAvailabilityDeclaration::class,
            'subject_id' => $declaration->id,
            'event' => 'faculty_availability_revision_required',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'term_id' => $term->id,
                'faculty_user_id' => $faculty->id,
                'affected_section_meeting_ids' => $affectedMeetingIds,
                'consequence' => 'The current timetable stays official until the Registrar publishes a validated successor.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
