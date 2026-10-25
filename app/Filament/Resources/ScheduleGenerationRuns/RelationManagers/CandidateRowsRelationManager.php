<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\RelationManagers;

use App\Actions\Scheduling\CandidateScheduleRowReviewService;
use App\Actions\Scheduling\RequestTimetableRepair;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\CandidateScheduleReviewForm;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class CandidateRowsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'candidateRows';

    protected static ?string $title = 'Candidate assignments';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Assignment comparison')
                    ->visible(fn (CandidateScheduleRow $record): bool => filled($record->supersedes_candidate_row_id) || in_array($record->change_type, ['RequestedRepair', 'RepairImpact', 'LocalAdjustment', 'Unchanged'], true))
                    ->description('Compare this successor with its recorded source. Review every changed meeting before accepting the whole candidate.')
                    ->schema([
                        TextEntry::make('comparison_change')->label('Change')
                            ->state(fn (CandidateScheduleRow $record): string => match ($record->change_type) {
                                'RequestedRepair' => 'Requested repair',
                                'RepairImpact' => 'Other meeting changed by repair',
                                'Unchanged' => 'Unchanged meeting',
                                'LocalAdjustment' => 'Local adjustment',
                                default => 'Recorded successor',
                            }),
                        TextEntry::make('comparison_source')->label('Source attempt')
                            ->state(fn (CandidateScheduleRow $record): ?string => ($previous = self::previousAssignment($record)) ? 'Attempt #'.$previous->schedule_run_id : null)
                            ->placeholder('Source assignment unavailable')
                            ->url(fn (CandidateScheduleRow $record): ?string => ($previous = self::previousAssignment($record)) ? ScheduleGenerationRunResource::getUrl('view', ['record' => $previous->schedule_run_id, 'termId' => $record->scheduleRun->term_id]) : null),
                        TextEntry::make('comparison_before')->label('Before')
                            ->state(fn (CandidateScheduleRow $record): array => ($previous = self::previousAssignment($record)) ? self::assignmentFacts($previous) : ['Previous assignment unavailable. Inspect the source evidence before accepting this successor.'])
                            ->listWithLineBreaks(),
                        TextEntry::make('comparison_after')->label('After')
                            ->state(fn (CandidateScheduleRow $record): array => self::assignmentFacts($record))
                            ->listWithLineBreaks(),
                    ])->columns(['default' => 1, 'md' => 2]),
                Section::make('Candidate meeting')
                    ->schema([
                        Group::make([
                            TextEntry::make('status')
                                ->label('Validation')
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? self::headline($state))
                                ->color(fn (string $state): string => self::statusColor($state)),
                            TextEntry::make('schedulingDemand.courseComponent.courseSpecification.title')
                                ->label('Course')
                                ->weight('semibold')
                                ->columnSpanFull()
                                ->placeholder('Course title not recorded'),
                            TextEntry::make('schedulingDemand.courseComponent.courseSpecification.course.code')
                                ->label('Course code')
                                ->placeholder('Not recorded'),
                            TextEntry::make('schedulingDemand.sectionDeliveryGroup.section.code')
                                ->label('Class')
                                ->placeholder('-')
                                ->columnSpanFull(),
                            TextEntry::make('schedulingDemand.courseComponent.component_type')
                                ->label('Component')
                                ->formatStateUsing(fn (?string $state): string => self::headline($state))
                                ->placeholder('-'),
                            TextEntry::make('schedulingDemand.modality')
                                ->label('Teaching mode')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => self::modalityLabel($state))
                                ->placeholder('-'),
                            TextEntry::make('faculty.name')
                                ->label('Faculty')
                                ->placeholder('-')
                                ->columnSpanFull(),
                            TextEntry::make('room.code')
                                ->label('Room')
                                ->placeholder('-'),
                            TextEntry::make('day_of_week')
                                ->label('Day')
                                ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-')),
                            TextEntry::make('starts_at')
                                ->label('Start')
                                ->formatStateUsing(fn (string $state): string => CarbonImmutable::parse($state)->format('g:i A'))
                                ->placeholder('-'),
                            TextEntry::make('ends_at')
                                ->label('End')
                                ->formatStateUsing(fn (string $state): string => CarbonImmutable::parse($state)->format('g:i A'))
                                ->placeholder('-'),
                        ])->dense()->columns(['default' => 1, 'md' => 2]),
                    ])
                    ->compact(),
                Section::make('Source and validation details')
                    ->collapsible()
                    ->collapsed(fn (CandidateScheduleRow $record): bool => self::payloadCount($record->warnings) === 0 && self::payloadCount($record->violations) === 0)
                    ->schema([
                        TextEntry::make('schedulingDemand.demand_key')
                            ->label('Scheduling demand reference')
                            ->placeholder('Not recorded'),
                        TextEntry::make('time_block_key')
                            ->label('Time block reference')
                            ->placeholder('Not recorded'),
                        TextEntry::make('score_summary')
                            ->label('Recorded solver scores')
                            ->state(fn (CandidateScheduleRow $record): array => self::scoreItems($record->scores))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Not recorded'),
                        TextEntry::make('warning_summary')
                            ->label('Warnings')
                            ->state(fn (CandidateScheduleRow $record): array => self::payloadMessages($record->warnings))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(fn (CandidateScheduleRow $record): string => is_array($record->warnings) && ($record->warnings === [] || ($record->warnings['items'] ?? null) === []) ? 'None recorded' : 'Not recorded'),
                        TextEntry::make('violation_summary')
                            ->label('Violations')
                            ->state(fn (CandidateScheduleRow $record): array => self::payloadMessages($record->violations))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(fn (CandidateScheduleRow $record): string => is_array($record->violations) && ($record->violations === [] || ($record->violations['items'] ?? null) === []) ? 'None recorded' : 'Not recorded'),
                    ])
                    ->columns([
                        'default' => 1,
                        'lg' => 3,
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(null)
            ->description(function (): ?string {
                if (data_get($this->ownerRun()->input_snapshot, 'operation.kind') !== 'repair') {
                    return null;
                }

                $counts = $this->ownerRun()->candidateRows()->pluck('change_type')->countBy();

                if ($counts->isEmpty()) {
                    return null;
                }

                return 'Requested changes: '.$counts->get('RequestedRepair', 0).' · Other changes: '.$counts->get('RepairImpact', 0).' · Unchanged meetings: '.$counts->get('Unchanged', 0).'. Counts cover the whole candidate.';
            })
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'faculty',
                'room',
                'supersedes.faculty',
                'supersedes.room',
                'supersedes.scheduleRun',
                'scheduleRun',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
            ]))
            ->columns([
                TextColumn::make('schedulingDemand.courseComponent.courseSpecification.title')
                    ->label('Class meeting')
                    ->description(fn (CandidateScheduleRow $record): string => collect([
                        $record->schedulingDemand?->courseComponent?->courseSpecification?->course?->code,
                        $record->schedulingDemand?->sectionDeliveryGroup?->section?->code,
                        self::headline($record->schedulingDemand?->courseComponent?->component_type),
                        self::modalityLabel($record->schedulingDemand?->modality),
                    ])->filter()->implode(' · '))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $rows): Builder => $rows
                        ->whereHas('schedulingDemand.courseComponent.courseSpecification', fn (Builder $specifications): Builder => $specifications
                            ->where('title', 'like', "%{$search}%")
                            ->orWhereHas('course', fn (Builder $courses): Builder => $courses->where('code', 'like', "%{$search}%")))
                        ->orWhereHas('schedulingDemand.sectionDeliveryGroup.section', fn (Builder $classes): Builder => $classes->where('code', 'like', "%{$search}%"))))
                    ->wrap()->weight('semibold')->placeholder('Course title not recorded'),
                TextColumn::make('day_of_week')
                    ->label('Day & time')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-'))
                    ->description(fn (CandidateScheduleRow $record): string => self::timeRange($record))
                    ->sortable()->wrap(),
                TextColumn::make('faculty.name')
                    ->label('Teacher & room')
                    ->description(fn (CandidateScheduleRow $record): string => filled($record->room?->code) ? 'Room '.$record->room->code : 'Room not recorded')
                    ->wrap()
                    ->placeholder('Teacher not recorded')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $assignments): Builder => $assignments
                        ->whereHas('faculty', fn (Builder $faculty): Builder => $faculty->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('room', fn (Builder $rooms): Builder => $rooms->where('code', 'like', "%{$search}%")))),
                TextColumn::make('status')
                    ->label('Checks & change')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? str($state)->headline()->toString())
                    ->description(fn (CandidateScheduleRow $record): string => implode(' · ', [
                        filled($record->change_type) ? self::headline($record->change_type) : 'Original',
                        self::payloadCount($record->violations).' violations',
                        self::payloadCount($record->warnings).' warnings',
                    ]))
                    ->sortable(),
            ])
            ->filters([
                Filter::make('changed_meetings')->label('Changed meetings only')
                    ->query(fn (Builder $query): Builder => $query->whereIn('change_type', ['RequestedRepair', 'RepairImpact', 'LocalAdjustment'])),
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('day_of_week')
                    ->label('Day')
                    ->options(SectionMeeting::dayOptions()),
                SelectFilter::make('teaching_mode')
                    ->label('Teaching mode')
                    ->options(SectionMeeting::modalityOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $candidateRows): Builder => $candidateRows->whereHas(
                            'schedulingDemand',
                            fn (Builder $demands): Builder => $demands->where('modality', $data['value']),
                        ),
                    )),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->defaultSort('day_of_week')
            ->recordActions([
                ViewAction::make()
                    ->label('Meeting details')->button()->color('gray')
                    ->modalHeading('Candidate meeting details'),
                ActionGroup::make([
                    $this->correctAssignmentAction(),
                    $this->findValidRepairAction(),
                ])->label('Changes')->button()->color('gray'),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading(fn (): string => $this->ownerRun()->candidateRows()->exists() ? 'No meetings match this view' : 'No candidate assignments are available')
            ->emptyStateDescription(fn (): string => $this->ownerRun()->candidateRows()->exists() ? 'Clear the search or filters to see all candidate meetings.' : 'The timetable request has not returned any assignments to review.');
    }

    private static function previousAssignment(CandidateScheduleRow $record): ?CandidateScheduleRow
    {
        $previous = $record->supersedes;

        return $previous instanceof CandidateScheduleRow
            && (int) $previous->scheduling_demand_id === (int) $record->scheduling_demand_id
            && (int) $previous->meeting_sequence === (int) $record->meeting_sequence
            && (int) $previous->scheduleRun?->term_id === (int) $record->scheduleRun?->term_id
                ? $previous : null;
    }

    /** @return list<string> */
    private static function assignmentFacts(CandidateScheduleRow $record): array
    {
        return [
            (SectionMeeting::dayOptions()[$record->day_of_week] ?? 'Day not recorded').' · '.(filled($record->starts_at) && filled($record->ends_at) ? self::timeRange($record) : 'Time not recorded').' · Asia/Manila',
            'Teacher: '.($record->faculty?->name ?? 'Not recorded'),
            'Room: '.($record->room?->code ?? 'No room assigned'),
        ];
    }

    private function correctAssignmentAction(): Action
    {
        return Action::make('correctAssignment')
            ->label('Correct assignment')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Correct candidate assignment')

            ->modalDescription(fn (CandidateScheduleRow $record): string => implode(' · ', [
                $record->schedulingDemand?->courseComponent?->courseSpecification?->title ?? 'Course not recorded',
                filled($record->schedulingDemand?->sectionDeliveryGroup?->section?->code)
                    ? 'Class '.$record->schedulingDemand->sectionDeliveryGroup->section->code
                    : 'Class not recorded',
                $record->day_of_week === null ? 'Day not recorded' : (SectionMeeting::dayOptions()[$record->day_of_week] ?? 'Day not recorded'),
                filled($record->starts_at) && filled($record->ends_at) ? self::timeRange($record) : 'Time not recorded',
            ]).'. TALA revalidates the complete candidate schedule before saving this correction.')
            ->modalSubmitActionLabel('Validate and save')
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn (CandidateScheduleRow $record): array => [
                'scheduling_demand_id' => $record->scheduling_demand_id,
                'faculty_user_id' => $record->faculty_user_id,
                'room_id' => $record->room_id,
                'day_of_week' => $record->day_of_week,
                'starts_at' => $record->starts_at,
                'ends_at' => $record->ends_at,
                'override_authority' => $record->override_authority,
                'override_reason' => $record->override_reason,
            ])
            ->schema(fn (): array => CandidateScheduleReviewForm::correctionSchema($this->ownerRun()))
            ->visible(fn (CandidateScheduleRow $record): bool => $this->canCorrect($record))
            ->action(function (array $data, CandidateScheduleRow $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                try {
                    $successor = app(CandidateScheduleRowReviewService::class)->revise($record, $data, $actor);

                    Notification::make()
                        ->title('Candidate assignment corrected')
                        ->body('The complete candidate schedule passed current hard-constraint validation.')
                        ->success()
                        ->send();

                    $this->redirect(ScheduleGenerationRunResource::getUrl('view', [
                        'record' => $successor,
                        'termId' => $successor->term_id,
                    ]));
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Candidate correction blocked')
                        ->body(self::validationMessage($exception))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
                } finally {
                    $this->dispatch('schedule-run-updated');
                }
            });
    }

    private function findValidRepairAction(): Action
    {
        return Action::make('findValidRepair')
            ->label('Find valid repair')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->modalHeading('Find a whole-Term repair')

            ->modalDescription(fn (CandidateScheduleRow $record): string => implode(' · ', [
                $record->schedulingDemand?->courseComponent?->courseSpecification?->title ?? 'Course not recorded',
                filled($record->schedulingDemand?->sectionDeliveryGroup?->section?->code)
                    ? 'Class '.$record->schedulingDemand->sectionDeliveryGroup->section->code
                    : 'Class not recorded',
                $record->day_of_week === null ? 'Day not recorded' : (SectionMeeting::dayOptions()[$record->day_of_week] ?? 'Day not recorded'),
                filled($record->starts_at) && filled($record->ends_at) ? self::timeRange($record) : 'Time not recorded',
            ]).'. The requested meeting is fixed as a hard requirement. Other meetings may move only in a previewable immutable candidate; nothing official changes until the whole repair is accepted and published.')
            ->modalSubmitActionLabel('Queue repair preview')
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn (CandidateScheduleRow $record): array => [
                'scheduling_demand_id' => $record->scheduling_demand_id,
                'faculty_user_id' => $record->faculty_user_id,
                'room_id' => $record->room_id,
                'day_of_week' => $record->day_of_week,
                'starts_at' => $record->starts_at,
                'ends_at' => $record->ends_at,
                'override_authority' => null,
                'override_reason' => null,
            ])
            ->schema(fn (): array => CandidateScheduleReviewForm::correctionSchema($this->ownerRun()))
            ->visible(fn (CandidateScheduleRow $record): bool => $this->canCorrect($record))
            ->action(function (array $data, CandidateScheduleRow $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                $repairRun = app(RequestTimetableRepair::class)->execute(
                    requestedRow: $record,
                    actor: $actor,
                    assignment: [
                        'faculty_user_id' => (int) $data['faculty_user_id'],
                        'room_id' => filled($data['room_id'] ?? null) ? (int) $data['room_id'] : null,
                        'day_of_week' => (int) $data['day_of_week'],
                        'starts_at' => (string) $data['starts_at'],
                        'ends_at' => (string) $data['ends_at'],
                    ],
                    reason: (string) $data['override_reason'],
                    authority: (string) $data['override_authority'],
                );

                Notification::make()
                    ->title('Repair preview queued')
                    ->body('The source candidate remains unchanged. Review every reported impact before accepting the successor.')
                    ->success()
                    ->send();
                $this->dispatch('schedule-run-updated');
                $this->redirect(ScheduleGenerationRunResource::getUrl('view', [
                    'record' => $repairRun,
                    'termId' => $repairRun->term_id,
                ]));
            });
    }

    #[On('schedule-run-updated')]
    public function refreshCandidateRows(): void
    {
        $this->resetTable();
    }

    private function canCorrect(CandidateScheduleRow $record): bool
    {
        $actor = auth()->user();
        $run = $record->scheduleRun;

        return $actor instanceof User
            && $run instanceof ScheduleGenerationRun
            && $run->status === ScheduleGenerationRun::StatusUnderReview
            && Gate::forUser($actor)->allows('reviewCandidates', $run);
    }

    private function ownerRun(): ScheduleGenerationRun
    {
        $run = $this->getOwnerRecord();

        if (! $run instanceof ScheduleGenerationRun) {
            abort(404);
        }

        return $run;
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            CandidateScheduleRow::StatusOk => 'OK',
            CandidateScheduleRow::StatusWarning => 'Warning',
            CandidateScheduleRow::StatusConflict => 'Conflict',
        ];
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            CandidateScheduleRow::StatusOk => 'success',
            CandidateScheduleRow::StatusWarning => 'warning',
            CandidateScheduleRow::StatusConflict => 'danger',
            default => 'gray',
        };
    }

    private static function payloadCount(mixed $payload): int
    {
        return count(self::payloadItems($payload));
    }

    private static function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : 'The complete candidate schedule failed current hard-constraint validation.';
    }

    private static function timeRange(CandidateScheduleRow $record): string
    {
        if (! filled($record->starts_at) || ! filled($record->ends_at)) {
            return '-';
        }

        return CarbonImmutable::parse($record->starts_at)->format('g:i A').' - '.CarbonImmutable::parse($record->ends_at)->format('g:i A');
    }

    private static function headline(?string $value): string
    {
        return filled($value) ? Str::headline(Str::lower($value)) : '-';
    }

    private static function modalityLabel(?string $modality): string
    {
        return SectionMeeting::modalityOptions()[$modality] ?? self::headline($modality);
    }

    /** @return list<string> */
    private static function scoreItems(mixed $scores): array
    {
        if (! is_array($scores)) {
            return [];
        }

        return collect($scores)
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value === null)
            ->map(fn (mixed $value, string|int $key): string => str((string) $key)->headline().': '.($value ?? '-'))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private static function payloadMessages(mixed $payload): array
    {
        return collect(self::payloadItems($payload))
            ->map(function (mixed $item): string {
                if (! is_array($item)) {
                    return is_scalar($item) ? (string) $item : 'Unspecified finding';
                }

                if (filled($item['message'] ?? null)) {
                    return (string) $item['message'];
                }

                return collect($item)
                    ->filter(fn (mixed $value): bool => is_scalar($value) || $value === null)
                    ->map(fn (mixed $value, string|int $key): string => str((string) $key)->headline().': '.($value ?? '-'))
                    ->implode('; ');
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<mixed>
     */
    private static function payloadItems(mixed $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $items = $payload['items'] ?? $payload;

        return is_array($items) ? array_values($items) : [];
    }
}
