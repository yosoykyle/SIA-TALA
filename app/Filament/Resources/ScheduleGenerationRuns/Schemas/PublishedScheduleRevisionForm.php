<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Actions\Calendar\TermTeachingGrid;
use App\Actions\Scheduling\ScheduleRevisionImpact;
use App\Actions\Scheduling\ScheduleRevisionImpactService;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\FacultyAvailabilityDeclaration;
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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublishedScheduleRevisionForm
{
    public const ScopeTemporarySubstitute = 'temporary_substitute';

    public const ScopePermanentTakeover = 'permanent_takeover';

    /** @return array<string, string> */
    public static function reassignmentScopeOptions(): array
    {
        return [
            self::ScopeTemporarySubstitute => 'Temporary substitute / relief coverage',
            self::ScopePermanentTakeover => 'Permanent takeover — also transfer class grades ownership',
        ];
    }

    /** @return list<Step> */
    public static function wizardSteps(ScheduleGenerationRun $run): array
    {
        $term = $run->term;
        $slotMinutes = $term instanceof Term
            ? app(TermTeachingGrid::class)->slotMinutes($term)
            : 30;

        return [
            Step::make('Select meetings')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->columns(1)
                ->schema([
                    Placeholder::make('selected_meetings_count')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::selectionCountBanner($get('section_meeting_ids')))
                        ->columnSpanFull(),
                    CheckboxList::make('section_meeting_ids')
                        ->label('Official meetings')
                        ->helperText('Search by subject, class, day, room, or teacher. Check one or more official meetings to revise.')
                        ->options(self::meetingOptions($run))
                        ->descriptions(self::meetingDescriptions($run))
                        ->searchable()
                        ->searchPrompt('Search by subject, class, day, room, or teacher…')
                        ->bulkToggleable()
                        ->columns(['default' => 1, 'md' => 2])
                        ->gridDirection(GridDirection::Row)
                        ->required()
                        ->rules(['array', 'min:1'])
                        ->live()
                        ->extraAttributes(['class' => 'tala-card-picker tala-revision-meeting-checklist'])
                        ->columnSpanFull(),
                ]),
            Step::make('Choose change')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Placeholder::make('selected_meetings_summary')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::selectedMeetingsInset($run, $get('section_meeting_ids')))
                        ->columnSpanFull(),
                    ToggleButtons::make('change_type')
                        ->label('What do you need to change?')
                        ->options([
                            ScheduleRevisionEvent::ChangeRoom => 'Room',
                            ScheduleRevisionEvent::ChangeFacultyReassignment => 'Teacher',
                            ScheduleRevisionEvent::ChangeTime => 'Day and time',
                            ScheduleRevisionEvent::ChangeDeliveryModality => 'Delivery mode',
                        ])
                        ->icons([
                            ScheduleRevisionEvent::ChangeRoom => Heroicon::OutlinedBuildingOffice2,
                            ScheduleRevisionEvent::ChangeFacultyReassignment => Heroicon::OutlinedUser,
                            ScheduleRevisionEvent::ChangeTime => Heroicon::OutlinedClock,
                            ScheduleRevisionEvent::ChangeDeliveryModality => Heroicon::OutlinedArrowsRightLeft,
                        ])
                        ->inline()
                        ->grouped()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state, Get $get) use ($run): void {
                            if ($state === ScheduleRevisionEvent::ChangeFacultyReassignment && blank($get('reassignment_scope'))) {
                                $set('reassignment_scope', self::ScopeTemporarySubstitute);
                            }

                            if ($state === ScheduleRevisionEvent::ChangeTime && filled($get('starts_at')) && blank($get('ends_at'))) {
                                $computedEnd = self::computedEndTime($run, $get('section_meeting_ids'), (string) $get('starts_at'));
                                if ($computedEnd !== null) {
                                    $set('ends_at', $computedEnd);
                                }
                            }
                        })
                        ->columnSpanFull(),
                    Select::make('replacement_room_id')
                        ->label('Replacement room')
                        ->helperText(fn (Get $get): ?string => self::roomRequirementHelper($run, $get('section_meeting_ids')))
                        ->options(fn (Get $get): array => self::roomOptions($run, $get('section_meeting_ids')))
                        ->searchable()
                        ->preload()
                        ->placeholder('Select a replacement room')
                        ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeRoom
                            || ($get('change_type') === ScheduleRevisionEvent::ChangeDeliveryModality
                                && self::selectedRequiresRoom($run, $get('section_meeting_ids'))))
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeRoom
                            || ($get('change_type') === ScheduleRevisionEvent::ChangeDeliveryModality
                                && self::selectedRequiresRoom($run, $get('section_meeting_ids'))))
                        ->columnSpan(fn (Get $get): int => $get('change_type') === ScheduleRevisionEvent::ChangeRoom ? 2 : 1)
                        ->live(),
                    Select::make('replacement_faculty_user_id')
                        ->label('Replacement teacher')
                        ->options(fn (Get $get): array => self::facultyOptions($run, $get('section_meeting_ids')))
                        ->searchable()
                        ->preload()
                        ->placeholder('Select a qualified teacher')
                        ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeFacultyReassignment)
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeFacultyReassignment)
                        ->columnSpanFull()
                        ->live(),
                    Radio::make('reassignment_scope')
                        ->label('Reassignment scope')
                        ->options(self::reassignmentScopeOptions())
                        ->descriptions([
                            self::ScopeTemporarySubstitute => 'Updates timetable meetings and notifies affected teachers and students while keeping the original grading teacher intact.',
                            self::ScopePermanentTakeover => 'Updates timetable meetings and transfers designated class grades ownership to the replacement teacher.',
                        ])
                        ->default(self::ScopeTemporarySubstitute)
                        ->afterStateHydrated(function (Radio $component, mixed $state): void {
                            if (blank($state)) {
                                $component->state(self::ScopeTemporarySubstitute);
                            }
                        })
                        ->dehydrateStateUsing(fn (mixed $state): string => filled($state) ? (string) $state : self::ScopeTemporarySubstitute)
                        ->extraAttributes(['class' => 'tala-choice-options'])
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeFacultyReassignment)
                        ->live()
                        ->columnSpanFull(),
                    Checkbox::make('add_co_faculty')
                        ->label('Also add replacement teacher as co-faculty for class list access')
                        ->helperText('Adds the substitute as co-faculty so they can view the class list while keeping the original grading teacher intact.')
                        ->default(false)
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeFacultyReassignment
                            && ($get('reassignment_scope') ?: self::ScopeTemporarySubstitute) === self::ScopeTemporarySubstitute)
                        ->live()
                        ->columnSpanFull(),
                    Select::make('day_of_week')
                        ->label('Replacement day')
                        ->options(self::dayOptions($run))
                        ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                        ->columnSpanFull()
                        ->live(),
                    TimePicker::make('starts_at')
                        ->label('Replacement start time')
                        ->timezone((string) config('app.timezone'))
                        ->seconds(false)
                        ->minutesStep($slotMinutes)
                        ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state, Get $get) use ($run): void {
                            $computedEnd = self::computedEndTime($run, $get('section_meeting_ids'), $state);
                            if ($computedEnd !== null) {
                                $set('ends_at', $computedEnd);
                            }
                        }),
                    TimePicker::make('ends_at')
                        ->label('Replacement end time')
                        ->helperText('Auto-filled from the selected meeting duration. Adjust if needed.')
                        ->timezone((string) config('app.timezone'))
                        ->seconds(false)
                        ->minutesStep($slotMinutes)
                        ->after('starts_at')
                        ->required(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeTime)
                        ->live(),
                    Placeholder::make('authoritative_modality')
                        ->label('Delivery mode')
                        ->content(fn (Get $get): string => self::authoritativeModalitySummary(
                            $run,
                            $get('section_meeting_ids'),
                        ))
                        ->visible(fn (Get $get): bool => $get('change_type') === ScheduleRevisionEvent::ChangeDeliveryModality),
                ]),
            Step::make('Review & publish')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->columns(1)
                ->schema([
                    Placeholder::make('impact_preview')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::previewContent($run, $get))
                        ->columnSpanFull(),
                    Textarea::make('reason')
                        ->label('Approved reason')
                        ->helperText('Effective immediately when published. TALA records the date and publishing actor automatically.')
                        ->placeholder('Enter the approved operational reason for this revision…')
                        ->rows(3)
                        ->maxLength(2000)
                        ->required()
                        ->columnSpanFull(),
                ]),
        ];
    }

    /** @return list<Component> */
    public static function schema(ScheduleGenerationRun $run, bool $cancelClass = false): array
    {
        if (! $cancelClass) {
            return self::wizardSteps($run);
        }

        return [
            FormSection::make('Class to cancel')
                ->description('Cancellation affects the whole class and every active official meeting. Review affected registrations before publication.')
                ->schema([
                    Hidden::make('change_type')->default(ScheduleRevisionEvent::ChangeSectionCancellation),
                    Select::make('section_id')
                        ->label('Selected class')
                        ->helperText('All delivery groups and active official meetings of this class are included.')
                        ->options(self::sectionOptions($run))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                ])->columns(1),
            FormSection::make('Review impact')
                ->description('Review the meetings and people affected before cancelling this class.')
                ->visible(fn (Get $get): bool => filled($get('section_id')))
                ->schema([
                    Placeholder::make('impact_preview')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::previewContent($run, $get))
                        ->columnSpanFull(),
                ]),
            FormSection::make('Record the approved cancellation')
                ->description('Effective immediately when published. TALA records the date and publishing actor automatically.')
                ->columns(1)
                ->visible(fn (Get $get): bool => filled($get('section_id')))
                ->schema([
                    Textarea::make('reason')
                        ->label('Approved reason')
                        ->helperText('Record the institutionally approved operational reason for cancelling this class.')
                        ->rows(3)
                        ->maxLength(2000)
                        ->required(),
                ]),
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
            self::impactChanges(self::changes($run, $data)),
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
                    'faculty_user_id' => self::requiredId($data, 'replacement_faculty_user_id', 'Select a replacement teacher.'),
                    ...self::facultyReassignmentMetadata($data),
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
    public static function reassignmentScope(array $data): string
    {
        $scope = trim((string) ($data['reassignment_scope'] ?? ''));

        if ($scope === '') {
            return self::ScopeTemporarySubstitute;
        }

        if (! array_key_exists($scope, self::reassignmentScopeOptions())) {
            throw ValidationException::withMessages([
                'reassignment_scope' => 'Select a supported faculty reassignment scope.',
            ]);
        }

        return $scope;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    public static function impactChanges(array $changes): array
    {
        return array_map(function (array $change): array {
            unset($change['reassignment_scope'], $change['add_co_faculty']);

            return $change;
        }, $changes);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function facultyReassignmentMetadata(array $data): array
    {
        if (! array_key_exists('reassignment_scope', $data) && ! array_key_exists('add_co_faculty', $data)) {
            return [];
        }

        $scope = self::reassignmentScope($data);

        return [
            'reassignment_scope' => $scope,
            'add_co_faculty' => $scope === self::ScopeTemporarySubstitute && (bool) ($data['add_co_faculty'] ?? false),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function section(ScheduleGenerationRun $run, array $data): Section
    {
        $sectionId = self::requiredId($data, 'section_id', 'Select the class to cancel.');
        $section = self::sectionsForRun($run)->firstWhere('id', $sectionId);

        if (! $section instanceof Section) {
            throw ValidationException::withMessages([
                'section_id' => 'Select a class with active official meetings in this published run.',
            ]);
        }

        return $section;
    }

    private static function selectionCountBanner(mixed $selectedMeetingIds): HtmlString
    {
        $count = count(self::selectedMeetingIds($selectedMeetingIds));
        $label = $count === 0
            ? '0 selected — choose one or more official meetings to continue.'
            : sprintf('%d %s selected', $count, Str::plural('meeting', $count));
        $tone = $count > 0 ? 'is-active' : 'is-empty';

        return new HtmlString(
            '<div class="tala-revision-selection-bar '.$tone.'" role="status" aria-live="polite">'
            .'<span>'.e($label).'</span>'
            .'</div>',
        );
    }

    private static function selectedMeetingsInset(ScheduleGenerationRun $run, mixed $selectedMeetingIds): HtmlString
    {
        $meetingIds = self::selectedMeetingIds($selectedMeetingIds);

        if ($meetingIds === []) {
            return new HtmlString(
                '<div class="tala-context-inset"><p class="tala-work-footnote">No meetings selected yet. Return to Step 1 to pick one or more official meetings.</p></div>',
            );
        }

        $selected = self::meetingsForRun($run)
            ->whereIn('id', $meetingIds)
            ->values();
        $count = $selected->count();
        $first = $selected->first();
        $summary = $first instanceof SectionMeeting
            ? self::meetingLabel($first).' · '.self::meetingDescription($first)
            : sprintf('%d %s selected', $count, Str::plural('meeting', $count));
        $extra = $count > 1 ? sprintf(' (+%d more)', $count - 1) : '';
        $heading = sprintf('Revising %d %s', $count, Str::plural('meeting', $count));

        return new HtmlString(
            '<div class="tala-context-inset tala-revision-selected-inset">'
            .'<div class="tala-revision-selected-inset__body">'
            .'<strong>'.e($heading).'</strong>'
            .'<span>'.e($summary.$extra).'</span>'
            .'</div>'
            .'<span class="tala-revision-selected-inset__hint">Click Step 1 to adjust</span>'
            .'</div>',
        );
    }

    private static function previewContent(ScheduleGenerationRun $run, Get $get): HtmlString
    {
        if (blank($get('change_type'))) {
            return new HtmlString('<p class="tala-work-footnote">Choose a change type and the meetings it affects to see the proposed impact.</p>');
        }

        $data = [
            'change_type' => $get('change_type'),
            'section_meeting_ids' => $get('section_meeting_ids'),
            'section_id' => $get('section_id'),
            'replacement_room_id' => $get('replacement_room_id'),
            'replacement_faculty_user_id' => $get('replacement_faculty_user_id'),
            'reassignment_scope' => $get('reassignment_scope'),
            'add_co_faculty' => $get('add_co_faculty'),
            'day_of_week' => $get('day_of_week'),
            'starts_at' => $get('starts_at'),
            'ends_at' => $get('ends_at'),
        ];

        try {
            $impact = self::preview($run, $data);
        } catch (ValidationException $exception) {
            return new HtmlString(
                '<div class="tala-revision-status is-blocked" role="status"><strong>Incomplete change details</strong><span>'.e(self::validationMessage($exception)).'</span></div>',
            );
        }

        $statusClass = $impact->passes() ? 'is-pass' : 'is-blocked';
        $status = $impact->passes()
            ? 'Meeting checks passed — publication rechecks the complete change'
            : 'Change blocked';
        $meetingCount = count($impact->meetingChanges());
        $studentCount = $impact->affectedStudents();
        $facultyCount = $impact->affectedFaculty();
        $scopeNote = '';

        if ($get('change_type') === ScheduleRevisionEvent::ChangeFacultyReassignment) {
            $scope = self::reassignmentScope($data);
            $scopeSummary = $scope === self::ScopePermanentTakeover
                ? 'Permanent takeover — also transfers designated class grades ownership to the replacement teacher.'
                : ((bool) ($data['add_co_faculty'] ?? false)
                    ? 'Temporary substitute / relief coverage — adds replacement teacher as co-faculty while keeping the original grading teacher intact.'
                    : 'Temporary substitute / relief coverage — updates timetable meetings while keeping the original grading teacher intact.');
            $scopeNote = '<div class="tala-context-inset"><p class="tala-revision-scope-note">'.e($scopeSummary).'</p></div>';
        }

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
            <div class="tala-revision-preview">
                <div class="tala-revision-status {$statusClass}" role="status">
                    <strong>{$status}</strong>
                </div>
                <dl class="tala-revision-metrics" aria-label="Revision impact summary">
                    <div><dt>Meetings</dt><dd>{$meetingCount}</dd></div>
                    <div><dt>Affected students</dt><dd>{$studentCount}</dd></div>
                    <div><dt>Affected teachers</dt><dd>{$facultyCount}</dd></div>
                </dl>
                {$scopeNote}
                <div class="tala-revision-changes">{$meetingRows}</div>
                <div class="tala-revision-findings">
                    <p class="tala-revision-findings__heading">Validation findings</p>
                    <ul class="tala-revision-findings__list">{$findingRows}</ul>
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

        return '<div class="tala-revision-change">'
            .'<p class="tala-revision-meeting">'.e($label).'</p>'
            .'<dl class="tala-revision-comparison">'
            .'<div><dt>Current</dt><dd>'.e(self::snapshotLabel($old)).'</dd></div>'
            .'<div><dt>Proposed</dt><dd>'.e(self::snapshotLabel($new)).'</dd></div></dl>'
            .'</div>';
    }

    /** @param array<string, mixed> $finding */
    private static function findingHtml(array $finding): string
    {
        $message = (string) ($finding['message'] ?? 'Unspecified validation finding.');
        $source = ScheduleGenerationRunInfolist::sourcePresentation($finding);
        $sourceHtml = filled($source['url'])
            ? '<a class="fi-link" href="'.e((string) $source['url']).'">'.e($source['label']).'</a>'
            : e($source['label']);

        return '<li>'.e($message).' <span class="tala-revision-finding-source">Source: '.$sourceHtml.'</span></li>';
    }

    /** @param array<string, mixed> $snapshot */
    private static function snapshotLabel(array $snapshot): string
    {
        $facultyId = is_numeric($snapshot['faculty_user_id'] ?? null) ? (int) $snapshot['faculty_user_id'] : null;
        $roomId = is_numeric($snapshot['room_id'] ?? null) ? (int) $snapshot['room_id'] : null;
        $faculty = $facultyId !== null ? User::query()->find($facultyId) : null;
        $room = $roomId !== null ? Room::query()->find($roomId) : null;
        $day = SectionMeeting::dayOptions()[(int) ($snapshot['day_of_week'] ?? 0)] ?? 'No day';
        $timeWindow = self::formatTimeWindow($snapshot['starts_at'] ?? null, $snapshot['ends_at'] ?? null);
        $modality = TermOffering::modalityOptions()[(string) ($snapshot['modality'] ?? '')]
            ?? Str::headline((string) ($snapshot['modality'] ?? '-'));

        return (($snapshot['state'] ?? null) === SectionMeeting::StateCancelled ? 'Cancelled · ' : '').implode(' · ', [
            $day.' '.$timeWindow,
            $faculty instanceof User ? $faculty->name : ($facultyId === null ? 'Unassigned teacher' : 'User #'.$facultyId),
            $room instanceof Room ? $room->code : ($roomId === null ? 'Online / No room' : 'Room #'.$roomId),
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
                        'section_meeting_ids' => 'Every selected meeting must have a recorded delivery mode.',
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
    private static function meetingDescriptions(ScheduleGenerationRun $run): array
    {
        return self::meetingsForRun($run)
            ->mapWithKeys(fn (SectionMeeting $meeting): array => [(int) $meeting->id => self::meetingDescription($meeting)])
            ->all();
    }

    /** @return array<int, string> */
    private static function sectionOptions(ScheduleGenerationRun $run): array
    {
        return self::sectionsForRun($run)
            ->mapWithKeys(function (Section $section): array {
                $course = $section->termOffering?->course();
                $courseCode = $course instanceof Course ? $course->code : 'Subject';

                return [(int) $section->id => ($section->termOffering?->courseSpecification()?->title ?? $courseCode).' · '.$section->code];
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
    private static function roomOptions(?ScheduleGenerationRun $run = null, mixed $selectedMeetingIds = null): array
    {
        $rooms = Room::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $requirements = $run instanceof ScheduleGenerationRun
            ? self::selectedRoomRequirements($run, $selectedMeetingIds)
            : ['requires_lab' => null, 'required_capacity' => 0];

        $requiresLab = $requirements['requires_lab'];
        $requiredCapacity = $requirements['required_capacity'];

        return $rooms
            ->map(function (Room $room) use ($requiresLab, $requiredCapacity): array {
                $roomTypeUpper = strtoupper((string) $room->room_type);
                $isOnlineRoom = $room->room_type === Room::TypeOnlineNoPhysicalRoom;
                $isLabRoom = str_contains($roomTypeUpper, 'LAB');
                $typeMatches = ! $isOnlineRoom && ($requiresLab === null || ($requiresLab ? $isLabRoom : ! $isLabRoom));
                $capacityMatches = $requiredCapacity <= 0 || $room->capacity >= $requiredCapacity;
                $compatible = $typeMatches && $capacityMatches;

                $base = collect([
                    $room->code,
                    $room->name,
                    Room::typeOptions()[$room->room_type] ?? $room->room_type,
                    "{$room->capacity} seats",
                ])->filter()->implode(' · ');

                if ($requiresLab === null && $requiredCapacity <= 0) {
                    return [
                        'id' => (int) $room->id,
                        'priority' => $isOnlineRoom ? 1 : 0,
                        'code' => (string) $room->code,
                        'label' => $base,
                    ];
                }

                if ($compatible) {
                    return [
                        'id' => (int) $room->id,
                        'priority' => 0,
                        'code' => (string) $room->code,
                        'label' => "{$base} · Compatible",
                    ];
                }

                $notes = [];
                if ($isOnlineRoom) {
                    $notes[] = 'Non-physical';
                } elseif (! $typeMatches) {
                    $notes[] = $requiresLab ? 'Not a lab' : 'Lab room';
                }
                if (! $capacityMatches) {
                    $notes[] = "Needs {$requiredCapacity} seats";
                }

                return [
                    'id' => (int) $room->id,
                    'priority' => 1,
                    'code' => (string) $room->code,
                    'label' => $base.' · '.implode(', ', $notes),
                ];
            })
            ->sortBy([
                ['priority', 'asc'],
                ['code', 'asc'],
            ])
            ->mapWithKeys(fn (array $entry): array => [$entry['id'] => $entry['label']])
            ->all();
    }

    private static function roomRequirementHelper(ScheduleGenerationRun $run, mixed $selectedMeetingIds): ?string
    {
        $requirements = self::selectedRoomRequirements($run, $selectedMeetingIds);
        $parts = [];

        if ($requirements['requires_lab'] === true) {
            $parts[] = 'Laboratory';
        } elseif ($requirements['requires_lab'] === false) {
            $parts[] = 'Lecture';
        }

        if ($requirements['required_capacity'] > 0) {
            $parts[] = "at least {$requirements['required_capacity']} seats";
        }

        if ($parts === []) {
            return null;
        }

        return 'Compatible rooms ('.implode(' · ', $parts).') are listed first.';
    }

    /** @return array{requires_lab: bool|null, required_capacity: int} */
    private static function selectedRoomRequirements(ScheduleGenerationRun $run, mixed $selectedMeetingIds): array
    {
        $meetingIds = self::selectedMeetingIds($selectedMeetingIds);
        if ($meetingIds === []) {
            return ['requires_lab' => null, 'required_capacity' => 0];
        }

        $meetings = self::meetingsForRun($run)
            ->whereIn('id', $meetingIds)
            ->values();

        if ($meetings->isEmpty()) {
            return ['requires_lab' => null, 'required_capacity' => 0];
        }

        $labFlags = $meetings
            ->map(function (SectionMeeting $meeting): bool {
                $component = $meeting->schedulingDemand?->courseComponent;
                $roomTypeDefault = strtoupper((string) ($component?->room_type_default ?? ''));

                return $component?->component_type === CourseComponent::TypeLaboratory
                    || str_contains($roomTypeDefault, 'LAB');
            })
            ->unique()
            ->values();

        $requiresLab = $labFlags->count() === 1 ? (bool) $labFlags->first() : null;
        $requiredCapacity = (int) $meetings
            ->map(fn (SectionMeeting $meeting): int => (int) (
                $meeting->schedulingDemand?->sectionDeliveryGroup?->expected_count
                ?? data_get($meeting->schedulingDemand?->source_snapshot, 'expected_count')
                ?? $meeting->schedulingDemand?->sectionDeliveryGroup?->section?->capacity
                ?? 0
            ))
            ->max();

        return [
            'requires_lab' => $requiresLab,
            'required_capacity' => max(0, $requiredCapacity),
        ];
    }

    private static function computedEndTime(ScheduleGenerationRun $run, mixed $selectedMeetingIds, ?string $startsAt): ?string
    {
        if (! filled($startsAt)) {
            return null;
        }

        $startMinutes = FacultyAvailabilityDeclaration::clockToMinutes((string) $startsAt);
        if ($startMinutes === null) {
            return null;
        }

        $meetingIds = self::selectedMeetingIds($selectedMeetingIds);
        $meeting = $meetingIds !== []
            ? self::meetingsForRun($run)->firstWhere('id', $meetingIds[0])
            : null;

        $durationMinutes = 60;
        if ($meeting instanceof SectionMeeting) {
            $currentStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $meeting->starts_at);
            $currentEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $meeting->ends_at);
            $durationMinutes = ($currentStart !== null && $currentEnd !== null && $currentEnd > $currentStart)
                ? ($currentEnd - $currentStart)
                : max(30, (int) ($meeting->schedulingDemand?->required_duration_minutes ?? 60));
        }

        $endMinutes = min((23 * 60) + 59, $startMinutes + $durationMinutes);

        return sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);
    }

    /** @return array<int, string> */
    private static function dayOptions(ScheduleGenerationRun $run): array
    {
        $allowedDays = $run->term ? array_column(app(TermTeachingGrid::class)->rows($run->term), 'day_of_week') : [];
        $days = SectionMeeting::dayOptions();

        if (! is_array($allowedDays) || $allowedDays === []) {
            return $run->term && app(TermTeachingGrid::class)->activePackage($run->term) ? [] : $days;
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
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
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
        $spec = $meeting->schedulingDemand?->courseComponent?->courseSpecification
            ?? $meeting->schedulingDemand?->termOffering?->courseSpecification();
        $course = $spec?->course ?? $meeting->schedulingDemand?->termOffering?->course();
        $courseCode = $course instanceof Course ? $course->code : 'Subject';
        $courseTitle = trim((string) ($spec?->title ?? ''));
        $sectionCode = $section instanceof Section ? $section->code : 'Class';

        $subject = ($courseTitle !== '' && $courseTitle !== $courseCode)
            ? "{$courseCode} — {$courseTitle}"
            : $courseCode;

        return "{$subject} ({$sectionCode})";
    }

    private static function meetingDescription(SectionMeeting $meeting): string
    {
        $day = SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Day '.(int) $meeting->day_of_week;
        $window = self::formatTimeWindow($meeting->starts_at, $meeting->ends_at);
        $roomCode = $meeting->room instanceof Room ? $meeting->room->code : 'Online / No room';
        $facultyName = $meeting->faculty instanceof User ? $meeting->faculty->name : 'Unassigned teacher';
        $sequence = 'Meeting '.max(1, (int) $meeting->meeting_sequence);

        return collect([$day, $window, $roomCode, $facultyName, $sequence])->implode(' · ');
    }

    private static function formatTimeWindow(mixed $startsAt, mixed $endsAt): string
    {
        if (! filled($startsAt) || ! filled($endsAt)) {
            return self::shortTime($startsAt).'–'.self::shortTime($endsAt);
        }

        try {
            $start = CarbonImmutable::parse((string) $startsAt);
            $end = CarbonImmutable::parse((string) $endsAt);

            return $start->format('A') === $end->format('A')
                ? $start->format('g:i').'–'.$end->format('g:i A')
                : $start->format('g:i A').'–'.$end->format('g:i A');
        } catch (\Throwable) {
            return self::shortTime($startsAt).'–'.self::shortTime($endsAt);
        }
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
