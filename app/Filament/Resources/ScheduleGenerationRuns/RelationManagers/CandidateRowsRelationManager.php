<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\RelationManagers;

use App\Actions\Scheduling\CandidateScheduleRowReviewService;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\CandidateScheduleReviewForm;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class CandidateRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'candidateRows';

    protected static ?string $title = 'Candidate Assignments';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Assignment')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => self::statusColor($state)),
                        TextEntry::make('schedulingDemand.demand_key')
                            ->label('Demand'),
                        TextEntry::make('schedulingDemand.sectionDeliveryGroup.section.code')
                            ->label('Section')
                            ->placeholder('-'),
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
                            ->placeholder('-'),
                        TextEntry::make('room.code')
                            ->label('Room')
                            ->placeholder('-'),
                        TextEntry::make('day_of_week')
                            ->label('Day')
                            ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-')),
                        TextEntry::make('starts_at')
                            ->label('Start')
                            ->placeholder('-'),
                        TextEntry::make('ends_at')
                            ->label('End')
                            ->placeholder('-'),
                        TextEntry::make('time_block_key')
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Review Payload')
                    ->schema([
                        TextEntry::make('score_summary')
                            ->label('Original Solver Scores')
                            ->state(fn (CandidateScheduleRow $record): array => self::scoreItems($record->scores))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('-'),
                        TextEntry::make('warning_summary')
                            ->label('Warnings')
                            ->state(fn (CandidateScheduleRow $record): array => self::payloadMessages($record->warnings))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('-'),
                        TextEntry::make('violation_summary')
                            ->label('Violations')
                            ->state(fn (CandidateScheduleRow $record): array => self::payloadMessages($record->violations))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('-'),
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
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'faculty',
                'room',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
            ]))
            ->columns([
                TextColumn::make('status')
                    ->label('Validation')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? str($state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('schedulingDemand.courseComponent.courseSpecification.course.code')
                    ->label('Course')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('schedulingDemand.demand_key')
                    ->label('Demand')
                    ->searchable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Section')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('schedulingDemand.courseComponent.component_type')
                    ->label('Component')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::headline($state))
                    ->placeholder('-'),
                TextColumn::make('schedulingDemand.modality')
                    ->label('Teaching mode')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::modalityLabel($state))
                    ->placeholder('-'),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('room.code')
                    ->label('Room')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-'))
                    ->sortable(),
                TextColumn::make('time_range')
                    ->label('Time')
                    ->state(fn (CandidateScheduleRow $record): string => self::timeRange($record))
                    ->placeholder('-'),
                TextColumn::make('violation_count')
                    ->label('Violations')
                    ->state(fn (CandidateScheduleRow $record): int => self::payloadCount($record->violations))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('warning_count')
                    ->label('Warnings')
                    ->state(fn (CandidateScheduleRow $record): int => self::payloadCount($record->warnings))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
            ])
            ->filters([
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
            ->defaultSort('day_of_week')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Review evidence'),
                    $this->correctAssignmentAction(),
                ]),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No candidate assignments are available')
            ->emptyStateDescription('The timetable request has not returned any assignments to review.');
    }

    private function correctAssignmentAction(): Action
    {
        return Action::make('correctAssignment')
            ->label('Correct assignment')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Correct Candidate Assignment')
            ->modalDescription('TALA revalidates the complete candidate schedule before saving this correction.')
            ->modalSubmitActionLabel('Validate and Save')
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
                    app(CandidateScheduleRowReviewService::class)->revise($record, $data, $actor);

                    Notification::make()
                        ->title('Candidate assignment corrected')
                        ->body('The complete candidate schedule passed current hard-constraint validation.')
                        ->success()
                        ->send();
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

        return mb_substr((string) $record->starts_at, 0, 5).' - '.mb_substr((string) $record->ends_at, 0, 5);
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
