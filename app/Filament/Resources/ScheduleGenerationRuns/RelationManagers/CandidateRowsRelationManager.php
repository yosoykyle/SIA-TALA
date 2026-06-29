<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\RelationManagers;

use App\Models\CandidateScheduleRow;
use App\Models\SectionMeeting;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CandidateRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'candidateRows';

    protected static ?string $title = 'Candidate Rows Review';

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
                        KeyValueEntry::make('scores')
                            ->placeholder('-'),
                        KeyValueEntry::make('warnings')
                            ->placeholder('-'),
                        KeyValueEntry::make('violations')
                            ->placeholder('-'),
                    ])
                    ->columns(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'faculty',
                'room',
                'schedulingDemand.courseComponent',
                'schedulingDemand.sectionDeliveryGroup.section',
            ]))
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('schedulingDemand.demand_key')
                    ->label('Demand')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Section')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('schedulingDemand.courseComponent.component_type')
                    ->label('Component')
                    ->badge()
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
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-')),
                TextColumn::make('starts_at')
                    ->label('Start')
                    ->placeholder('-'),
                TextColumn::make('ends_at')
                    ->label('End')
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
            ])
            ->defaultSort('id')
            ->recordActions([
                ViewAction::make()
                    ->label('Details'),
            ])
            ->toolbarActions([]);
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

    /**
     * @return list<array<string, mixed>>
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
