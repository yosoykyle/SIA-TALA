<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilityDeclaration;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

final class RecordFacultyAvailabilityDeclaration
{
    /**
     * @param  list<array{day_of_week: int, starts_at: string, ends_at: string}>  $hardUnavailability
     * @param  list<array{day_of_week: int, start_time?: string, end_time?: string, starts_at?: string, ends_at?: string}>  $teachingWindows
     */
    public function execute(
        Term $term,
        User $faculty,
        User $actor,
        ?string $declaration = null,
        array $hardUnavailability = [],
        ?string $correctionReason = null,
        ?string $availabilityMode = null,
        array $teachingWindows = [],
    ): FacultyAvailabilityDeclaration {
        if (! $actor->is($faculty)
            || ! $actor->hasRole(User::StaffRoleFaculty)
            || ! $faculty->hasRole(User::StaffRoleFaculty)) {
            abort(403);
        }

        if ($term->state === Term::StateClosed) {
            throw ValidationException::withMessages([
                'term' => 'Availability cannot be updated because this Term is closed.',
            ]);
        }

        $resolved = $this->resolvePayload(
            $term,
            $declaration,
            $hardUnavailability,
            $availabilityMode,
            $teachingWindows,
        );

        $conflictCount = 0;

        $record = DB::transaction(function () use ($term, $faculty, $actor, $resolved, $correctionReason, &$conflictCount): FacultyAvailabilityDeclaration {
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
                'declaration' => $resolved['declaration'],
                'availability_mode' => $resolved['availability_mode'],
                'hard_unavailability' => $resolved['hard_unavailability'],
                'teaching_windows' => $resolved['teaching_windows'],
                'correction_reason' => $correctionReason,
                'declared_at' => now(),
            ]);

            ScheduleGenerationRun::query()
                ->where('term_id', $term->id)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                    ScheduleGenerationRun::StatusUnderReview,
                ])
                ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);

            $conflictCount = $this->recordPublishedImpact($term, $faculty, $actor, $record);

            return $record;
        }, attempts: 5);

        if ($conflictCount > 0) {
            $this->notifyRegistrarsOfPublishedConflict($term, $faculty, $conflictCount);
        }

        return $record;
    }

    /**
     * @param  list<array<string, mixed>>  $hardUnavailability
     * @param  list<array<string, mixed>>  $teachingWindows
     * @return array{declaration:string,availability_mode:?string,hard_unavailability:list<array{day_of_week:int,starts_at:string,ends_at:string}>,teaching_windows:?list<array{day_of_week:int,start_time:string,end_time:string}>}
     */
    private function resolvePayload(
        Term $term,
        ?string $declaration,
        array $hardUnavailability,
        ?string $availabilityMode,
        array $teachingWindows,
    ): array {
        if ($availabilityMode !== null) {
            if (! array_key_exists($availabilityMode, FacultyAvailabilityDeclaration::modeOptions())) {
                throw ValidationException::withMessages([
                    'availability_mode' => 'Select one of the supported Faculty availability modes.',
                ]);
            }

            if ($availabilityMode === FacultyAvailabilityDeclaration::ModeWindows) {
                return [
                    'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
                    'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
                    'hard_unavailability' => [],
                    'teaching_windows' => $this->validatedTeachingWindows($term, $teachingWindows),
                ];
            }

            if ($availabilityMode === FacultyAvailabilityDeclaration::ModeCannotTeach) {
                return [
                    'declaration' => FacultyAvailabilityDeclaration::DeclarationUnavailable,
                    'availability_mode' => FacultyAvailabilityDeclaration::ModeCannotTeach,
                    'hard_unavailability' => [],
                    'teaching_windows' => null,
                ];
            }

            return [
                'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
                'availability_mode' => FacultyAvailabilityDeclaration::ModeAnyApproved,
                'hard_unavailability' => [],
                'teaching_windows' => null,
            ];
        }

        if ($declaration === null || ! array_key_exists($declaration, FacultyAvailabilityDeclaration::declarationOptions())) {
            throw ValidationException::withMessages([
                'declaration' => 'Select one of the supported Faculty availability declarations.',
            ]);
        }

        if ($declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable) {
            return [
                'declaration' => FacultyAvailabilityDeclaration::DeclarationUnavailable,
                'availability_mode' => FacultyAvailabilityDeclaration::ModeCannotTeach,
                'hard_unavailability' => [],
                'teaching_windows' => null,
            ];
        }

        $normalizedHardUnavailability = $this->normalizedIntervals($hardUnavailability);

        return [
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => $normalizedHardUnavailability === []
                ? FacultyAvailabilityDeclaration::ModeAnyApproved
                : null,
            'hard_unavailability' => $normalizedHardUnavailability,
            'teaching_windows' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @return list<array{day_of_week:int,start_time:string,end_time:string}>
     */
    private function validatedTeachingWindows(Term $term, array $windows): array
    {
        $calendar = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('teachingGridRows')
            ->first();

        if (! $calendar instanceof TermCalendarPackage || $calendar->teachingGridRows->isEmpty()) {
            throw ValidationException::withMessages([
                'teaching_windows' => 'An active core teaching calendar with approved hours is required before choosing teaching times.',
            ]);
        }

        if ($windows === []) {
            throw ValidationException::withMessages([
                'teaching_windows' => 'Add at least one weekly teaching window or choose another availability option.',
            ]);
        }

        $gridByDay = $calendar->teachingGridRows->keyBy('day_of_week');

        return collect($windows)
            ->map(function (array $window) use ($gridByDay): array {
                $day = (int) ($window['day_of_week'] ?? 0);
                $rawStart = $this->normalizedTime((string) ($window['start_time'] ?? $window['starts_at'] ?? ''));
                $rawEnd = $this->normalizedTime((string) ($window['end_time'] ?? $window['ends_at'] ?? ''));
                $startMinutes = FacultyAvailabilityDeclaration::clockToMinutes($rawStart);
                $endMinutes = FacultyAvailabilityDeclaration::clockToMinutes($rawEnd);

                if ($day < 1 || $day > 7
                    || $rawStart === null
                    || $rawEnd === null
                    || $startMinutes === null
                    || $endMinutes === null
                    || $startMinutes >= $endMinutes) {
                    throw ValidationException::withMessages([
                        'teaching_windows' => 'Every teaching window needs an approved weekday and a start time earlier than its end time.',
                    ]);
                }

                if (($startMinutes % 30) !== 0 || ($endMinutes % 30) !== 0
                    || ! str_ends_with($rawStart, ':00')
                    || ! str_ends_with($rawEnd, ':00')) {
                    throw ValidationException::withMessages([
                        'teaching_windows' => 'Teaching windows must align to the 30-minute scheduling grid.',
                    ]);
                }

                $gridRow = $gridByDay->get($day);

                if (! $gridRow instanceof TermTeachingGridRow) {
                    throw ValidationException::withMessages([
                        'teaching_windows' => 'Chosen teaching windows must fall on approved teaching days in the active calendar.',
                    ]);
                }

                $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->starts_at);
                $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->ends_at);

                if ($gridStart === null || $gridEnd === null || $startMinutes < $gridStart || $endMinutes > $gridEnd) {
                    throw ValidationException::withMessages([
                        'teaching_windows' => 'Chosen teaching windows must stay within the approved daily teaching hours.',
                    ]);
                }

                $breaks = collect(is_array($gridRow->breaks) ? $gridRow->breaks : [])
                    ->filter(fn (mixed $break): bool => is_array($break))
                    ->map(function (array $break): ?array {
                        $bStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['starts_at'] ?? ''));
                        $bEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['ends_at'] ?? ''));

                        return ($bStart !== null && $bEnd !== null && $bStart < $bEnd)
                            ? ['start' => $bStart, 'end' => $bEnd]
                            : null;
                    })
                    ->filter()
                    ->values()
                    ->all();

                $usableSegments = FacultyAvailabilityDeclaration::subtractIntervals(
                    [['start' => $startMinutes, 'end' => $endMinutes]],
                    $breaks,
                );

                if ($usableSegments === []) {
                    throw ValidationException::withMessages([
                        'teaching_windows' => 'A chosen teaching window cannot fall wholly inside a mandatory break.',
                    ]);
                }

                return [
                    'day_of_week' => $day,
                    'start_time' => FacultyAvailabilityDeclaration::minutesToHourMinute($startMinutes),
                    'end_time' => FacultyAvailabilityDeclaration::minutesToHourMinute($endMinutes),
                ];
            })
            ->unique(fn (array $window): string => implode('|', $window))
            ->sortBy(fn (array $window): string => sprintf(
                '%d|%s|%s',
                $window['day_of_week'],
                $window['start_time'],
                $window['end_time'],
            ))
            ->values()
            ->all();
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

    private function recordPublishedImpact(
        Term $term,
        User $faculty,
        User $actor,
        FacultyAvailabilityDeclaration $declaration,
    ): int {
        $affectedMeetings = SectionMeeting::query()
            ->activeOfficial()
            ->where('faculty_user_id', $faculty->id)
            ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $term->id))
            ->with([
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
            ])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (SectionMeeting $meeting): bool => $declaration->conflictsWithMeeting(
                (int) $meeting->day_of_week,
                (string) $meeting->starts_at,
                (string) $meeting->ends_at,
            ))
            ->values();

        if ($affectedMeetings->isEmpty()) {
            return 0;
        }

        $affectedMeetingIds = $affectedMeetings
            ->map(fn (SectionMeeting $meeting): int => (int) $meeting->id)
            ->values()
            ->all();

        $meetingDetails = $affectedMeetings->map(function (SectionMeeting $meeting): array {
            $specification = $meeting->schedulingDemand?->courseComponent?->courseSpecification;
            $course = $specification?->course;
            $section = $meeting->schedulingDemand?->sectionDeliveryGroup?->section;

            return [
                'section_meeting_id' => (int) $meeting->id,
                'section_id' => $section?->id !== null ? (int) $section->id : null,
                'course_code' => $course?->code,
                'course_title' => $specification?->title,
                'section_code' => $section?->code,
                'day_of_week' => (int) $meeting->day_of_week,
                'starts_at' => (string) $meeting->starts_at,
                'ends_at' => (string) $meeting->ends_at,
                'modality' => $meeting->modality,
            ];
        })->all();

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
                'term_id' => (int) $term->id,
                'faculty_user_id' => (int) $faculty->id,
                'faculty_name' => (string) $faculty->name,
                'declaration_version' => (int) $declaration->version,
                'effective_mode' => $declaration->effectiveMode(),
                'mode_label' => $declaration->modeLabel(),
                'actor_id' => (int) $actor->id,
                'actor_name' => (string) $actor->name,
                'reason' => $declaration->correction_reason,
                'affected_section_meeting_ids' => $affectedMeetingIds,
                'affected_meetings' => $meetingDetails,
                'consequence' => 'The current timetable stays official until the Registrar publishes a validated successor.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $affectedMeetings->count();
    }

    private function notifyRegistrarsOfPublishedConflict(Term $term, User $faculty, int $conflictCount): void
    {
        $registrars = User::query()
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleRegistrar))
            ->orderBy('id')
            ->get();

        if ($registrars->isEmpty()) {
            return;
        }

        $termLabel = trim((string) $term->label) !== '' ? (string) $term->label : 'this term';
        $meetingPhrase = $conflictCount === 1 ? '1 official meeting' : "{$conflictCount} official meetings";
        $verb = $conflictCount === 1 ? 'conflicts' : 'conflict';
        $timetableUrl = Route::has('filament.admin.term-planning.pages.timetable')
            ? route('filament.admin.term-planning.pages.timetable', ['termId' => (int) $term->id])
            : null;

        $notification = Notification::make()
            ->title("{$faculty->name} changed availability after publication")
            ->body("{$meetingPhrase} in {$termLabel} {$verb} with their updated hours. Review and publish an official revision when ready.")
            ->warning();

        if ($timetableUrl !== null) {
            $notification->actions([
                Action::make('reviewOfficialTimetable')
                    ->label('Review official timetable')
                    ->url($timetableUrl),
            ]);
        }

        $notification->sendToDatabase($registrars);
    }
}
