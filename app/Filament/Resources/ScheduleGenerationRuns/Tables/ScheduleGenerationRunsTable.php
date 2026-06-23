<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Tables;

use App\Actions\Scheduling\SchedulePublishService;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class ScheduleGenerationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'requester', 'committer', 'publisher'])->withCount('sectionMeetings'))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors(ScheduleGenerationRun::statusColors())
                    ->searchable(),
                TextColumn::make('section_meetings_count')
                    ->label('Published Meetings')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextColumn::make('generated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('committer.name')
                    ->label('Committed By')
                    ->placeholder('-'),
                TextColumn::make('committed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('publisher.name')
                    ->label('Published By')
                    ->placeholder('-'),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ScheduleGenerationRun::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::publishAction(),
            ])
            ->toolbarActions([]);
    }

    private static function publishAction(): Action
    {
        return Action::make('publishSchedule')
            ->label('Publish Schedule')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->schema([
                Textarea::make('publish_note')
                    ->label('Publish note')
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->requiresConfirmation()
            ->visible(fn (ScheduleGenerationRun $record): bool => self::registrarCanSchedule()
                && $record->canBePublished())
            ->action(fn (array $data, ScheduleGenerationRun $record): null => self::publish(
                $record,
                $data['publish_note'] ?? null,
                'Schedule run published',
            ));
    }

    private static function publish(
        ScheduleGenerationRun $record,
        ?string $note,
        string $successTitle,
    ): null {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return null;
        }

        try {
            app(SchedulePublishService::class)->publish($record, $actor, $note);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Schedule publish failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        return null;
    }

    private static function registrarCanSchedule(): bool
    {
        return auth()->user()?->can('manage-schedules') ?? false;
    }
}
