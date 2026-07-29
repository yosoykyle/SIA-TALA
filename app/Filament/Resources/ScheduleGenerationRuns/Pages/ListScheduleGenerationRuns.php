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
use Illuminate\Validation\ValidationException;
use Throwable;

class ListScheduleGenerationRuns extends ListRecords
{
    protected static string $resource = ScheduleGenerationRunResource::class;

    public function getTitle(): string
    {
        return 'Generated Timetables';
    }

    public function getSubheading(): string
    {
        return 'Review every generation request and open its assignments, validation evidence, solution quality, and publication controls.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dispatchSolverRun')
                ->label('Generate Timetable')
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
                        ->helperText('Only ready Schedule Requirements are included. Generation is blocked while any requirement for the term still needs correction.'),
                ])
                ->modalHeading('Generate Timetable')
                ->modalDescription('Captures the current ready requirements as one protected request, then sends it to the configured timetable generator. Nothing becomes official until Registrar review and publication.')
                ->modalSubmitActionLabel('Generate Timetable')
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
                            ->title('Timetable generation requested')
                            ->body("Request #{$run->id} captured the current ready requirements. Its status refreshes automatically every five seconds.")
                            ->success()
                            ->send();
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first();

                        Notification::make()
                            ->title('Timetable generation blocked')
                            ->body(is_string($message) ? $message : 'Review the Schedule Requirement findings and try again.')
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Timetable generation failed')
                            ->body('TALA could not queue the timetable request. Try again or ask the System Administrator to review the application log.')
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
