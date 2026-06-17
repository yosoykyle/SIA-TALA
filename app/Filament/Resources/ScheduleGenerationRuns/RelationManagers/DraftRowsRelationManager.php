<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\RelationManagers;

use App\Actions\Scheduling\ScheduleDraftRowReviewService;
use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class DraftRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'draftRows';

    protected static ?string $title = 'Draft Rows Review';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('section.name')
                    ->label('Section'),
                TextEntry::make('sectionDeliveryGroup.name')
                    ->label('Delivery Group')
                    ->placeholder('-'),
                TextEntry::make('subject.code')
                    ->label('Subject'),
                TextEntry::make('faculty.name')
                    ->label('Faculty')
                    ->placeholder('-'),
                TextEntry::make('room')
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
                TextEntry::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionMeeting::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextEntry::make('conflict_summary')
                    ->label('Blocking Conflicts')
                    ->state(fn (ScheduleDraftRow $record): string => self::payloadSummary($record->conflict_payload))
                    ->placeholder('None')
                    ->columnSpanFull(),
                TextEntry::make('warning_summary')
                    ->label('Warnings / Review Notes')
                    ->state(fn (ScheduleDraftRow $record): string => self::payloadSummary($record->warning_payload))
                    ->placeholder('None')
                    ->columnSpanFull(),
                TextEntry::make('override_reason')
                    ->label('Registrar Review Reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('editor.name')
                    ->label('Edited By')
                    ->placeholder('-'),
                TextEntry::make('edited_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with(['section', 'sectionDeliveryGroup', 'subject', 'faculty', 'editor']))
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('section.name')
                    ->label('Section')
                    ->searchable(),
                TextColumn::make('sectionDeliveryGroup.name')
                    ->label('Delivery Group')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('subject.code')
                    ->label('Subject')
                    ->searchable(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-')),
                TextColumn::make('starts_at')
                    ->label('Start')
                    ->placeholder('-'),
                TextColumn::make('ends_at')
                    ->label('End')
                    ->placeholder('-'),
                TextColumn::make('room')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionMeeting::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('conflict_count')
                    ->label('Conflicts')
                    ->state(fn (ScheduleDraftRow $record): int => self::payloadCount($record->conflict_payload))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('warning_count')
                    ->label('Warnings')
                    ->state(fn (ScheduleDraftRow $record): int => self::payloadCount($record->warning_payload))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
            ])
            ->defaultSort('id')
            ->recordActions([
                ViewAction::make()
                    ->label('Details'),
                self::reviseAction(),
            ])
            ->toolbarActions([]);
    }

    private static function reviseAction(): Action
    {
        return Action::make('reviseDraftRow')
            ->label('Revise')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(fn (ScheduleDraftRow $record): bool => self::canRevise($record))
            ->fillForm(fn (ScheduleDraftRow $record): array => [
                'section_delivery_group_id' => $record->section_delivery_group_id,
                'faculty_id' => $record->faculty_id,
                'room' => $record->room,
                'day_of_week' => $record->day_of_week,
                'starts_at' => $record->starts_at,
                'ends_at' => $record->ends_at,
                'modality' => $record->modality,
                'override_reason' => $record->override_reason,
            ])
            ->schema([
                Select::make('section_delivery_group_id')
                    ->label('Delivery Group')
                    ->options(fn (ScheduleDraftRow $record): array => SectionDeliveryGroup::query()
                        ->where('section_id', $record->section_id)
                        ->where('status', SectionDeliveryGroup::StatusActive)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (SectionDeliveryGroup $group): array => [
                            $group->id => $group->displayLabel(),
                        ])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('faculty_id')
                    ->label('Faculty')
                    ->options(fn (): array => User::role(User::StaffRoleFaculty)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('day_of_week')
                    ->label('Day')
                    ->options(SectionMeeting::dayOptions())
                    ->required(),
                TimePicker::make('starts_at')
                    ->label('Start')
                    ->seconds(false)
                    ->required(),
                TimePicker::make('ends_at')
                    ->label('End')
                    ->seconds(false)
                    ->after('starts_at')
                    ->required(),
                Select::make('modality')
                    ->options(SectionMeeting::modalityOptions())
                    ->required(),
                TextInput::make('room')
                    ->maxLength(255)
                    ->helperText('Required only for on-site or blended rows; Laravel validation will re-check the modality rule.'),
                Textarea::make('override_reason')
                    ->label('Review Reason')
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->modalHeading('Revise Draft Row')
            ->modalDescription('The row and the full draft set will be revalidated before commit is allowed.')
            ->modalSubmitActionLabel('Save and Revalidate')
            ->action(function (array $data, ScheduleDraftRow $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    app(ScheduleDraftRowReviewService::class)->revise($record, $data, $actor);

                    Notification::make()
                        ->title('Draft row revalidated')
                        ->body('The full draft set was rechecked. Remaining conflicts, if any, are still blocking commit.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Draft row revision blocked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function canRevise(ScheduleDraftRow $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('manage-schedules')) {
            return false;
        }

        $run = $record->generationRun;

        return $run instanceof ScheduleGenerationRun
            && ! in_array($run->status, [
                ScheduleGenerationRun::StatusCommitted,
                ScheduleGenerationRun::StatusPublished,
                ScheduleGenerationRun::StatusAbandoned,
                ScheduleGenerationRun::StatusSuperseded,
            ], true);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            ScheduleDraftRow::StatusOk => 'OK',
            ScheduleDraftRow::StatusWarning => 'Warning',
            ScheduleDraftRow::StatusConflict => 'Conflict',
        ];
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            ScheduleDraftRow::StatusOk => 'success',
            ScheduleDraftRow::StatusWarning => 'warning',
            ScheduleDraftRow::StatusConflict => 'danger',
            default => 'gray',
        };
    }

    private static function payloadCount(?array $payload): int
    {
        return count(self::payloadItems($payload));
    }

    private static function payloadSummary(?array $payload): string
    {
        return collect(self::payloadItems($payload))
            ->map(fn (array $item): string => collect([
                $item['type'] ?? null,
                $item['message'] ?? null,
            ])->filter()->implode(': '))
            ->filter()
            ->implode("\n");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function payloadItems(?array $payload): array
    {
        $items = $payload['items'] ?? $payload ?? [];

        return is_array($items) ? array_values($items) : [];
    }
}
