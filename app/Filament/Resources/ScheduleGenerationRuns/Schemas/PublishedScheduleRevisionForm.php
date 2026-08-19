<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Actions\Scheduling\ScheduleRevisionImpact;
use App\Actions\Scheduling\ScheduleRevisionImpactService;
use App\Models\Course;
use App\Models\FacultyQualification;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublishedScheduleRevisionForm
{
    /** @return list<Component> */
    public static function schema(ScheduleGenerationRun $run): array
    {
        $term = $run->term;
        $slotMinutes = $term instanceof Term
            ? max(1, (int) $term->scheduling_slot_minutes)
            : 30;

        return [
            Select::make('change_type')
                ->label('Change Type')
                ->options(ScheduleRevisionEvent::changeTypeOptions())
                ->required()
                ->live(),
            Select::make('section_meeting_ids')
                ->label('Affected Meetings')
                ->helperText('Select every linked official meeting that must change in this operation.')
                ->options(self::meetingOptions($run))
                ->multiple()
                ->searchable()
                ->preload()
                ->required(fn (Get $get): bool => filled($get('change_type'))
                    && $get('change_type') !== ScheduleRevisionEvent::ChangeSectionCancellation)
                ->visible(fn (Get $get): bool => filled($get('change_type'))
                    && $get('change_type') !== ScheduleRevisionEvent::ChangeSectionCancellation)
                ->live()
                ->columnSpanFull(),
            Select::make('section_id')
                ->label('Section to Cancel')
                ->helperText('Cancellation applies to the whole Section, all active delivery groups, and all active official meetings.')
                ->options(self::sectionOptions($run))
                ->searchable()
                ->preload()
                ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeSectionCancellation)
                ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeSectionCancellation)
                ->live()
                ->columnSpanFull(),
            Select::make('replacement_room_id')
                ->label('Replacement Room')
                ->options(self::roomOptions())
                ->searchable()
                ->preload()
                ->placeholder('No physical room required')
                ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeRoom
                    || ($get('change_type') === ScheduleRevisionEvent::ChangeDeliveryModality
                        && self::selectedRequiresRoom($run, $get('section_meeting_ids'))))
                ->visible(fn (Get $get): bool => in_array($get('change_type'), [
                    ScheduleRevisionEvent::ChangeRoom,
                    ScheduleRevisionEvent::ChangeDeliveryModality,
                ], true))
                ->live(),
            Select::make('replacement_faculty_user_id')
                ->label('Replacement Faculty')
                ->options(fn (Get $get): array => self::facultyOptions($run, $get('section_meeting_ids')))
                ->searchable()
                ->preload()
                ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeFacultyReassignment)
                ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeFacultyReassignment)
                ->live(),
            Select::make('day_of_week')
                ->label('Replacement Day')
                ->options(self::dayOptions($run))
                ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                ->live(),
            TimePicker::make('starts_at')
                ->label('Replacement Start Time')
                ->timezone((string) config('app.timezone'))
                ->seconds(false)
                ->minutesStep($slotMinutes)
                ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                ->live(),
            TimePicker::make('ends_at')
                ->label('Replacement End Time')
                ->timezone((string) config('app.timezone'))
                ->seconds(false)
                ->minutesStep($slotMinutes)
                ->after('starts_at')
                ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                ->live(),
            Placeholder::make('authoritative_modality')
                ->label('Authoritative Modality')
                ->content(fn (Get $get): string => self::authoritativeModalitySummary(
                    $run,
                    $get('section_meeting_ids'),
                ))
                ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeDeliveryModality),
            Placeholder::make('effective_date')
                ->label('Immediate effective date')
                ->content(CarbonImmutable::now(config('app.timezone'))->toFormattedDateString())
                ->helperText('The effective date is system-derived when the revision is applied.'),
            Textarea::make('authority_reference')
                ->label('External timetable sign-off reference')
                ->helperText('Record the attributable authority for this complete successor version.')
                ->rows(2)
                ->maxLength(255)
                ->required()
                ->columnSpanFull(),
            Textarea::make('reason')
                ->label('Approved Reason')
                ->helperText('Record the institutionally approved operational reason for this revision.')
                ->rows(3)
                ->maxLength(2000)
                ->required()
                ->columnSpanFull(),
            Placeholder::make('impact_preview')
                ->label('Impact Preview')
                ->content(fn (Get $get): HtmlString => self::previewContent($run, $get))
                ->visible(fn (Get $get): bool => filled($get('change_type')))
                ->columnSpanFull(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function preview(ScheduleGenerationRun $run, array $data): ScheduleRevisionImpact
    {
        if (($data['change_type'] ?? null) === ScheduleRevisionEvent::ChangeSectionCancellation) {
            return app(ScheduleRevisionImpactService::class)->previewCancellation(
                $run,
                self::section($run, $data),
            );
        }

        return app(ScheduleRevisionImpactService::class)->preview(
            $run,
            (string) ($data['change_type'] ?? ''),
            self::changes($run, $data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function changes(ScheduleGenerationRun $run, array $data): array
    {
        $changeType = (string) ($data['change_type'] ?? '');
        $meetingIds = self::selectedMeetingIds($data['section_meeting_ids'] ?? null);

        if ($meetingIds === []) {
            throw ValidationException::withMessages([
                'section_meeting_ids' => 'Select at least one active official meeting.',
            ]);
        }

        return match ($changeType) {
            ScheduleRevisionEvent::ChangeRoom => collect($meetingIds)
                ->map(fn (int $meetingId): array => [
                    'section_meeting_id' => $meetingId,
                    'room_id' => self::requiredId($data, 'replacement_room_id', 'Select a replacement room.'),
                ])
                ->all(),
            ScheduleRevisionEvent::ChangeFacultyReassignment => collect($meetingIds)
                ->map(fn (int $meetingId): array => [
                    'section_meeting_id' => $meetingId,
                    'faculty_user_id' => self::requiredId($data, 'replacement_faculty_user_id', 'Select a replacement faculty member.'),
                ])
                ->all(),
            ScheduleRevisionEvent::ChangeTime => collect($meetingIds)
                ->map(fn (int $meetingId): array => [
                    'section_meeting_id' => $meetingId,
                    'day_of_week' => self::requiredId($data, 'day_of_week', 'Select a replacement day.'),
                    'starts_at' => self::requiredString($data, 'starts_at', 'Select a replacement start time.'),
                    'ends_at' => self::requiredString($data, 'ends_at', 'Select a replacement end time.'),
                ])
                ->all(),
            ScheduleRevisionEvent::ChangeDeliveryModality => self::modalityChanges($run, $meetingIds, $data),
            default => throw ValidationException::withMessages([
                'change_type' => 'Select a supported published-schedule revision type.',
            ]),
        };
    }

    /** @param array<string, mixed> $data */
    public static function section(ScheduleGenerationRun $run, array $data): Section
    {
        $sectionId = self::requiredId($data, 'section_id', 'Select the Section to cancel.');
        $section = self::sectionsForRun($run)->firstWhere('id', $sectionId);

        if (! $section instanceof Section) {
            throw ValidationException::withMessages([
                'section_id' => 'Select a Section with active official meetings in this published run.',
            ]);
        }

        return $section;
    }

    private static function previewContent(ScheduleGenerationRun $run, Get $get): HtmlString
    {
        $data = [
            'change_type' => $get('change_type'),
            'section_meeting_ids' => $get('section_meeting_ids'),
            'section_id' => $get('section_id'),
            'replacement_room_id' => $get('replacement_room_id'),
            'replacement_faculty_user_id' => $get('replacement_faculty_user_id'),
            'day_of_week' => $get('day_of_week'),
            'starts_at' => $get('starts_at'),
            'ends_at' => $get('ends_at'),
        ];

        try {
            $impact = self::preview($run, $data);
        } catch (ValidationException $exception) {
            return new HtmlString(
                '<p class="text-sm text-gray-600 dark:text-gray-300">'.e(self::validationMessage($exception)).'</p>',
            );
        }

        $statusClass = $impact->passes()
            ? 'text-success-700 dark:text-success-400'
            : 'text-danger-700 dark:text-danger-400';
        $status = $impact->passes() ? 'Ready to apply' : 'Blocked';
        $summary = sprintf(
            '%d meeting(s), %d affected student(s), and %d affected faculty member(s).',
            count($impact->meetingChanges()),
            $impact->affectedStudents(),
            $impact->affectedFaculty(),
        );
        $meetingRows = collect($impact->meetingChanges())
            ->map(fn (array $change): string => self::meetingChangeHtml($change))
            ->implode('');
        $findingRows = collect($impact->findings())
            ->map(fn (array $finding): string => self::findingHtml($finding))
            ->implode('');

        if ($findingRows === '') {
            $findingRows = '<li>No validation findings.</li>';
        }

        return new HtmlString(<<<HTML
            <div class="space-y-3 text-sm">
                <p class="font-semibold {$statusClass}">{$status}</p>
                <p>{$summary}</p>
                <div class="space-y-2">{$meetingRows}</div>
                <div>
                    <p class="font-medium">Validation findings</p>
                    <ul class="list-disc space-y-1 ps-5">{$findingRows}</ul>
                </div>
            </div>
            HTML);
    }

    /** @param array<string, mixed> $change */
    private static function meetingChangeHtml(array $change): string
    {
        $meetingId = (int) ($change['section_meeting_id'] ?? 0);
        $meeting = SectionMeeting::query()
            ->with('schedulingDemand.sectionDeliveryGroup.section')
            ->find($meetingId);
        $label = $meeting instanceof SectionMeeting ? self::meetingLabel($meeting) : 'Meeting #'.$meetingId;
        $old = is_array($change['old'] ?? null) ? $change['old'] : [];
        $new = is_array($change['new'] ?? null) ? $change['new'] : [];

        return '<div class="rounded-md border border-gray-200 p-3 dark:border-white/10">'
            .'<p class="font-medium">'.e($label).'</p>'
            .'<p><span class="font-medium">Current:</span> '.e(self::snapshotLabel($old)).'</p>'
            .'<p><span class="font-medium">Proposed:</span> '.e(self::snapshotLabel($new)).'</p>'
            .'</div>';
    }

    /** @param array<string, mixed> $finding */
    private static function findingHtml(array $finding): string
    {
        $message = (string) ($finding['message'] ?? 'Unspecified validation finding.');
        $source = ScheduleGenerationRunInfolist::sourcePresentation($finding);
        $sourceHtml = filled($source['url'])
            ? '<a class="text-primary-600 underline" href="'.e((string) $source['url']).'">'.e($source['label']).'</a>'
            : e($source['label']);

        return '<li>'.e($message).' <span class="text-gray-500">Source: '.$sourceHtml.'</span></li>';
    }

    /** @param array<string, mixed> $snapshot */
    private static function snapshotLabel(array $snapshot): string
    {
        $facultyId = is_numeric($snapshot['faculty_user_id'] ?? null) ? (int) $snapshot['faculty_user_id'] : null;
        $roomId = is_numeric($snapshot['room_id'] ?? null) ? (int) $snapshot['room_id'] : null;
        $faculty = $facultyId !== null ? User::query()->find($facultyId) : null;
        $room = $roomId !== null ? Room::query()->find($roomId) : null;
        $day = SectionMeeting::dayOptions()[(int) ($snapshot['day_of_week'] ?? 0)] ?? 'No day';
        $start = self::shortTime($snapshot['starts_at'] ?? null);
        $end = self::shortTime($snapshot['ends_at'] ?? null);
        $modality = TermOffering::modalityOptions()[(string) ($snapshot['modality'] ?? '')]
            ?? Str::headline((string) ($snapshot['modality'] ?? '-'));

        return implode(' | ', [
            $day.' '.$start.'-'.$end,
            $faculty instanceof User ? $faculty->name : ($facultyId === null ? 'No faculty' : 'User #'.$facultyId),
            $room instanceof Room ? $room->code : ($roomId === null ? 'No physical room' : 'Room #'.$roomId),
            $modality,
        ]);
    }

    /**
     * @param  list<int>  $meetingIds
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function modalityChanges(ScheduleGenerationRun $run, array $meetingIds, array $data): array
    {
        $meetings = self::meetingsForRun($run)
            ->whereIn('id', $meetingIds)
            ->keyBy('id');

        if ($meetings->count() !== count($meetingIds)) {
            throw ValidationException::withMessages([
                'section_meeting_ids' => 'Every selected meeting must be active in this published run.',
            ]);
        }

        return collect($meetingIds)
            ->map(function (int $meetingId) use ($meetings, $data): array {
                /** @var SectionMeeting $meeting */
                $meeting = $meetings->get($meetingId);
                $modality = self::authoritativeModality($meeting);

                if ($modality === null) {
                    throw ValidationException::withMessages([
                        'section_meeting_ids' => 'Every selected meeting must have an authoritative delivery modality.',
                    ]);
                }

                $roomId = is_numeric($data['replacement_room_id'] ?? null)
                    ? (int) $data['replacement_room_id']
                    : null;

                if ($modality === TermOffering::ModalityFaceToFace && $roomId === null) {
                    throw ValidationException::withMessages([
                        'replacement_room_id' => 'Face-to-Face delivery requires a replacement room.',
                    ]);
                }

                return [
                    'section_meeting_id' => $meetingId,
                    'modality' => $modality,
                    'room_id' => $modality === TermOffering::ModalityFaceToFace ? $roomId : null,
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    private static function meetingOptions(ScheduleGenerationRun $run): array
    {
        return self::meetingsForRun($run)
            ->mapWithKeys(fn (SectionMeeting $meeting): array => [(int) $meeting->id => self::meetingLabel($meeting)])
            ->all();
    }

    /** @return array<int, string> */
    private static function sectionOptions(ScheduleGenerationRun $run): array
    {
        return self::sectionsForRun($run)
            ->mapWithKeys(function (Section $section): array {
                $course = $section->termOffering?->course();
                $courseCode = $course instanceof Course ? $course->code : 'Course';

                return [(int) $section->id => $section->code.' | '.$courseCode];
            })
            ->all();
    }

    /** @return array<int, string> */
    private static function facultyOptions(ScheduleGenerationRun $run, mixed $selectedMeetingIds): array
    {
        $meetingIds = self::selectedMeetingIds($selectedMeetingIds);

        if ($meetingIds === []) {
            return [];
        }

        $courseIds = self::meetingsForRun($run)
            ->whereIn('id', $meetingIds)
            ->map(fn (SectionMeeting $meeting): mixed => $meeting->schedulingDemand?->termOffering?->course()?->getKey())
            ->filter(fn (mixed $courseId): bool => is_numeric($courseId))
            ->map(fn (mixed $courseId): int => (int) $courseId)
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            return [];
        }

        return FacultyQualification::query()
            ->with('faculty')
            ->where('is_active', true)
            ->whereIn('course_id', $courseIds)
            ->whereHas('faculty', fn (Builder $query): Builder => $query->where('status', User::StatusActive))
            ->get()
            ->groupBy('faculty_user_id')
            ->filter(fn ($qualifications): bool => $qualifications->pluck('course_id')->unique()->count() === $courseIds->count())
            ->mapWithKeys(function ($qualifications): array {
                $qualification = $qualifications->first();
                $faculty = $qualification instanceof FacultyQualification ? $qualification->faculty : null;

                return $faculty instanceof User ? [(int) $faculty->id => $faculty->name] : [];
            })
            ->sort()
            ->all();
    }

    /** @return array<int, string> */
    private static function roomOptions(): array
    {
        return Room::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Room $room): array => [(int) $room->id => $room->displayLabel()])
            ->all();
    }

    /** @return array<int, string> */
    private static function dayOptions(ScheduleGenerationRun $run): array
    {
        $allowedDays = $run->term?->getAttribute('scheduling_days');
        $days = SectionMeeting::dayOptions();

        if (! is_array($allowedDays) || $allowedDays === []) {
            return $days;
        }

        return collect($allowedDays)
            ->mapWithKeys(fn (mixed $day): array => [(int) $day => $days[(int) $day] ?? 'Day '.(int) $day])
            ->all();
    }

    private static function selectedRequiresRoom(ScheduleGenerationRun $run, mixed $selectedMeetingIds): bool
    {
        return self::meetingsForRun($run)
            ->whereIn('id', self::selectedMeetingIds($selectedMeetingIds))
            ->contains(fn (SectionMeeting $meeting): bool => self::authoritativeModality($meeting) === TermOffering::ModalityFaceToFace);
    }

    private static function authoritativeModalitySummary(ScheduleGenerationRun $run, mixed $selectedMeetingIds): string
    {
        $modalities = self::meetingsForRun($run)
            ->whereIn('id', self::selectedMeetingIds($selectedMeetingIds))
            ->map(fn (SectionMeeting $meeting): ?string => self::authoritativeModality($meeting))
            ->filter()
            ->unique()
            ->map(fn (string $modality): string => TermOffering::modalityOptions()[$modality] ?? Str::headline($modality))
            ->values();

        return $modalities->isEmpty() ? 'Select affected meetings.' : $modalities->implode(', ');
    }

    private static function authoritativeModality(SectionMeeting $meeting): ?string
    {
        $modality = $meeting->schedulingDemand?->sectionDeliveryGroup?->modality
            ?: $meeting->schedulingDemand?->termOffering?->modality;

        return filled($modality) ? (string) $modality : null;
    }

    /** @return Collection<int, SectionMeeting> */
    private static function meetingsForRun(ScheduleGenerationRun $run): Collection
    {
        return SectionMeeting::query()
            ->where('schedule_run_id', $run->id)
            ->where('state', SectionMeeting::StateActive)
            ->with([
                'faculty',
                'room',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section.termOffering.curriculumEntry.courseSpecification.course',
                'schedulingDemand.termOffering',
            ])
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Section> */
    private static function sectionsForRun(ScheduleGenerationRun $run): Collection
    {
        $sectionIds = self::meetingsForRun($run)
            ->map(fn (SectionMeeting $meeting): mixed => $meeting->schedulingDemand?->sectionDeliveryGroup?->section_id)
            ->filter(fn (mixed $sectionId): bool => is_numeric($sectionId))
            ->map(fn (mixed $sectionId): int => (int) $sectionId)
            ->unique();

        return Section::query()
            ->whereIn('id', $sectionIds)
            ->with('termOffering.curriculumEntry.courseSpecification.course')
            ->orderBy('code')
            ->get();
    }

    private static function meetingLabel(SectionMeeting $meeting): string
    {
        $section = $meeting->schedulingDemand?->sectionDeliveryGroup?->section;
        $course = $meeting->schedulingDemand?->termOffering?->course();
        $day = SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Day '.(int) $meeting->day_of_week;

        return collect([
            $section instanceof Section ? $section->code : 'Section',
            $course instanceof Course ? $course->code : 'Course',
            'Meeting '.$meeting->meeting_sequence,
            $day.' '.self::shortTime($meeting->starts_at).'-'.self::shortTime($meeting->ends_at),
        ])->implode(' | ');
    }

    /** @return list<int> */
    private static function selectedMeetingIds(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $meetingId): bool => filter_var($meetingId, FILTER_VALIDATE_INT) !== false)
            ->map(fn (mixed $meetingId): int => (int) $meetingId)
            ->filter(fn (int $meetingId): bool => $meetingId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    private static function requiredId(array $data, string $key, string $message): int
    {
        $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT);

        if ($value === false || $value < 1) {
            throw ValidationException::withMessages([$key => $message]);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key, string $message): string
    {
        $value = trim((string) ($data[$key] ?? ''));

        if ($value === '') {
            throw ValidationException::withMessages([$key => $message]);
        }

        return $value;
    }

    private static function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : 'Complete the revision fields to preview current validation.';
    }

    private static function shortTime(mixed $time): string
    {
        return filled($time) ? mb_substr((string) $time, 0, 5) : '-';
    }
}
