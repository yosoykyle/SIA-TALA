<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\ScheduleGenerationService;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListScheduleGenerationRuns extends ListRecords
{
    protected static string $resource = ScheduleGenerationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateSchedule')
                ->label('Generate Schedule')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('manage-schedules') ?? false)
                ->schema([
                    Select::make('term_id')
                        ->label('Term')
                        ->options(fn (): array => Term::query()
                            ->orderByDesc('scheduling_starts_at')
                            ->orderByDesc('id')
                            ->pluck('term_name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('Generation is blocked until term fields, section planning, curriculum demand, capacity, and room rules pass readiness.'),
                ])
                ->modalHeading('Generate Automatic Schedule')
                ->modalDescription('Creates an immutable input snapshot and queues the private Cloud Run OR-Tools solver.')
                ->modalSubmitActionLabel('Generate')
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    try {
                        $run = app(ScheduleGenerationService::class)->generate(
                            Term::query()->findOrFail((int) $data['term_id']),
                            $actor,
                        );

                        Notification::make()
                            ->title('Schedule generation queued')
                            ->body("Run #{$run->id} captured the section-planning snapshot and queued solver dispatch.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Schedule generation blocked')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
