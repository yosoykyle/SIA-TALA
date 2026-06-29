<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\ScheduleGenerationService;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Models\ScheduleGenerationRun;
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
            Action::make('dispatchSolverRun')
                ->label('Dispatch Solver Run')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('create', ScheduleGenerationRun::class) ?? false)
                ->schema([
                    Select::make('term_id')
                        ->label('Term')
                        ->options(fn (): array => Term::query()
                            ->orderByDesc('starts_on')
                            ->orderByDesc('id')
                            ->pluck('label', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('Dispatch uses only READY_FOR_REVIEW Scheduling Demand rows and blocks if any demand for the term still needs action.'),
                ])
                ->modalHeading('Dispatch Solver Run')
                ->modalDescription('Creates an immutable TAL-61 demand payload and queues the configured scheduling solver client.')
                ->modalSubmitActionLabel('Dispatch')
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
                            ->title('Solver run queued')
                            ->body("Run #{$run->id} captured READY_FOR_REVIEW demand rows for dispatch.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Solver run blocked')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
