<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class CandidateScheduleReviewForm
{
    /** @return list<Component> */
    public static function correctionSchema(ScheduleGenerationRun $run): array
    {
        return [
            Hidden::make('scheduling_demand_id')
                ->dehydrated(false),
            ...self::assignmentFields($run),
            ...self::evidenceFields(),
        ];
    }

    /** @return list<Component> */
    public static function manualOverrideSchema(ScheduleGenerationRun $run): array
    {
        return [
            Repeater::make('assignments')
                ->label('Complete Replacement Assignments')
                ->helperText('Every required demand meeting is listed. TALA validates the complete set before replacing any candidate rows.')
                ->schema([
                    Hidden::make('scheduling_demand_id'),
                    Hidden::make('meeting_sequence'),
                    Hidden::make('assignment_label')
                        ->dehydrated(false),
                    Placeholder::make('assignment_identity')
                        ->label('Assignment')
                        ->content(fn (Get $get): string => (string) ($get('assignment_label') ?? 'Demand assignment'))
                        ->columnSpanFull(),
                    ...self::assignmentFields($run),
                ])
                ->itemLabel(fn (?array $state): ?string => $state['assignment_label'] ?? null)
                ->required()
                ->minItems(1)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->collapsible()
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->columnSpanFull(),
            ...self::evidenceFields(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function replacementRows(ScheduleGenerationRun $run): array
    {
        $snapshot = app(ScheduleSolverSnapshotService::class)->currentForRun($run);
        $existingRows = $run->candidateRows()
            ->get()
            ->keyBy(fn (CandidateScheduleRow $row): string => self::identity(
                (int) $row->scheduling_demand_id,
                (int) $row->meeting_sequence,
            ));

        return collect($snapshot['scheduling_demands'] ?? [])
            ->filter(fn (mixed $demand): bool => is_array($demand))
            ->flatMap(function (array $demand) use ($existingRows): array {
                $demandId = (int) ($demand['scheduling_demand_id'] ?? 0);
                $meetingCount = max(1, (int) ($demand['meeting_count'] ?? 1));

                return collect(range(1, $meetingCount))
                    ->map(function (int $meetingSequence) use ($demand, $demandId, $existingRows): array {
                        $existing = $existingRows->get(self::identity($demandId, $meetingSequence));
                        $existing = $existing instanceof CandidateScheduleRow ? $existing : null;
                        $startsAt = $existing instanceof CandidateScheduleRow
                            ? $existing->starts_at
                            : ($demand['fixed_start_time'] ?? null);

                        return [
                            'scheduling_demand_id' => $demandId,
                            'meeting_sequence' => $meetingSequence,
                            'assignment_label' => self::assignmentLabel($demand, $meetingSequence),
                            'faculty_user_id' => $existing instanceof CandidateScheduleRow
                                ? $existing->faculty_user_id
                                : ($demand['fixed_faculty_user_id'] ?? null),
                            'room_id' => $existing instanceof CandidateScheduleRow
                                ? $existing->room_id
                                : ($demand['fixed_room_id'] ?? null),
                            'day_of_week' => $existing instanceof CandidateScheduleRow
                                ? $existing->day_of_week
                                : ($demand['fixed_day_of_week'] ?? null),
                            'starts_at' => self::timeValue($startsAt),
                            'ends_at' => self::timeValue($existing instanceof CandidateScheduleRow ? $existing->ends_at : null)
                                ?? self::calculatedEnd($startsAt, (int) ($demand['required_duration_minutes'] ?? 0)),
                        ];
                    })
                    ->all();
            })
            ->values()
            ->all();
    }

    /** @return list<Component> */
    private static function assignmentFields(ScheduleGenerationRun $run): array
    {
        $term = $run->term;
        $slotMinutes = $term instanceof Term
            ? max(1, (int) $term->scheduling_slot_minutes)
            : 30;

        return [
            Select::make('faculty_user_id')
                ->label('Faculty')
                ->options(fn (Get $get): array => self::facultyOptions(
                    is_numeric($get('scheduling_demand_id')) ? (int) $get('scheduling_demand_id') : null,
                    is_numeric($get('faculty_user_id')) ? (int) $get('faculty_user_id') : null,
                ))
                ->searchable()
                ->preload()
                ->required(),
            Select::make('room_id')
                ->label('Room')
                ->options(fn (Get $get): array => self::roomOptions(
                    is_numeric($get('room_id')) ? (int) $get('room_id') : null,
                ))
                ->searchable()
                ->preload()
                ->placeholder('No room (online only)')
                ->nullable(),
            Select::make('day_of_week')
                ->label('Day')
                ->options(self::dayOptions($run))
                ->required(),
            TimePicker::make('starts_at')
                ->label('Start Time')
                ->timezone((string) config('app.timezone'))
                ->seconds(false)
                ->minutesStep($slotMinutes)
                ->required(),
            TimePicker::make('ends_at')
                ->label('End Time')
                ->timezone((string) config('app.timezone'))
                ->seconds(false)
                ->minutesStep($slotMinutes)
                ->after('starts_at')
                ->required(),
        ];
    }

    /** @return list<Component> */
    private static function evidenceFields(): array
    {
        return [
            TextInput::make('override_authority')
                ->label('Override Authority')
                ->helperText('Record the approving person, office, or policy authority.')
                ->maxLength(255)
                ->required(),
            Textarea::make('override_reason')
                ->label('Reason')
                ->helperText('Explain why this correction or Manual Schedule Override is institutionally necessary.')
                ->rows(3)
                ->maxLength(2000)
                ->required()
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, string> */
    private static function facultyOptions(?int $demandId, ?int $currentFacultyId): array
    {
        $demand = $demandId !== null
            ? SchedulingDemand::query()->with('courseComponent.courseSpecification')->find($demandId)
            : null;
        $component = $demand instanceof SchedulingDemand ? $demand->courseComponent : null;
        $specification = $component instanceof CourseComponent ? $component->courseSpecification : null;
        $courseId = $specification instanceof CourseSpecification ? $specification->course_id : null;
        $faculty = FacultyQualification::query()
            ->with('faculty')
            ->where('is_active', true)
            ->when($courseId !== null, fn (Builder $query): Builder => $query->where('course_id', $courseId))
            ->whereHas('faculty', fn (Builder $query): Builder => $query->where('status', User::StatusActive))
            ->get()
            ->mapWithKeys(function (FacultyQualification $qualification): array {
                $faculty = $qualification->faculty;

                if (! $faculty instanceof User) {
                    return [];
                }

                return [(int) $qualification->faculty_user_id => $faculty->name];
            })
            ->filter()
            ->sort()
            ->all();

        if ($currentFacultyId !== null && ! array_key_exists($currentFacultyId, $faculty)) {
            $current = User::query()->find($currentFacultyId);

            if ($current instanceof User) {
                $faculty[$currentFacultyId] = $current->name.' (current; revalidation required)';
            }
        }

        return $faculty;
    }

    /** @return array<int, string> */
    private static function roomOptions(?int $currentRoomId): array
    {
        $rooms = Room::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Room $room): array => [(int) $room->id => $room->displayLabel()])
            ->all();

        if ($currentRoomId !== null && ! array_key_exists($currentRoomId, $rooms)) {
            $current = Room::query()->find($currentRoomId);

            if ($current instanceof Room) {
                $rooms[$currentRoomId] = $current->displayLabel().' (inactive; revalidation required)';
            }
        }

        return $rooms;
    }

    /** @return array<int, string> */
    private static function dayOptions(ScheduleGenerationRun $run): array
    {
        $term = $run->term;
        $allowedDays = $term instanceof Term ? $term->getAttribute('scheduling_days') : null;
        $days = SectionMeeting::dayOptions();

        if (! is_array($allowedDays) || $allowedDays === []) {
            return $days;
        }

        return collect($allowedDays)
            ->mapWithKeys(fn (mixed $day): array => [(int) $day => $days[(int) $day] ?? 'Day '.(int) $day])
            ->all();
    }

    /** @param array<string, mixed> $demand */
    private static function assignmentLabel(array $demand, int $meetingSequence): string
    {
        $identity = $demand['demand_key'] ?? 'Demand #'.($demand['scheduling_demand_id'] ?? '?');
        $course = $demand['course_code'] ?? 'Course';
        $component = Str::headline(Str::lower((string) ($demand['component_type'] ?? 'component')));

        return "{$course} | {$component} | Meeting {$meetingSequence} | {$identity}";
    }

    private static function calculatedEnd(mixed $startsAt, int $durationMinutes): ?string
    {
        $start = self::timeValue($startsAt);

        if ($start === null || $durationMinutes < 1) {
            return null;
        }

        $startTime = CarbonImmutable::createFromFormat('H:i:s', $start);

        return $startTime->addMinutes($durationMinutes)->format('H:i:s');
    }

    private static function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private static function identity(int $demandId, int $meetingSequence): string
    {
        return $demandId.':'.$meetingSequence;
    }
}
