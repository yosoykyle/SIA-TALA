<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\SchedulePublishService;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ViewScheduleGenerationRun extends ViewRecord
{
    protected static string $resource = ScheduleGenerationRunResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->publishScheduleAction(),
        ];
    }

    public function publishScheduleAction(): Action
    {
        return Action::make('publishSchedule')
            ->label('Publish Schedule')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Publish Schedule')
            ->modalDescription(fn (): string => $this->publicationConfirmationDescription())
            ->modalSubmitActionLabel('Publish Schedule')
            ->schema([
                Textarea::make('publication_note')
                    ->label('Publication note')
                    ->maxLength(2000)
                    ->helperText('Optional. Record the reason for accepting advisory warnings or other publication context.'),
            ])
            ->visible(fn (): bool => $this->canPublish())
            ->action(function (array $data): void {
                $publisher = auth()->user();

                if (! $publisher instanceof User) {
                    abort(403);
                }

                $run = $this->getRecord();

                if (! $run instanceof ScheduleGenerationRun) {
                    abort(404);
                }

                $this->record = app(SchedulePublishService::class)->publish(
                    $run,
                    $publisher,
                    $data['publication_note'] ?? null,
                );

                Notification::make()
                    ->title('Schedule published')
                    ->success()
                    ->send();
            });
    }

    private function canPublish(): bool
    {
        $publisher = auth()->user();
        $run = $this->getRecord();

        return $publisher instanceof User
            && $run instanceof ScheduleGenerationRun
            && Gate::forUser($publisher)->allows('publish', $run)
            && $run->canBePublished();
    }

    private function publicationConfirmationDescription(): string
    {
        /** @var ScheduleGenerationRun $run */
        $run = $this->getRecord();
        $summary = $run->publicationSummary();

        return sprintf(
            '%d candidate %s, %d warning %s, and %d conflict or violation %s. Publication makes these assignments official and supersedes the prior published version for this term.',
            $summary['assignments'],
            $summary['assignments'] === 1 ? 'assignment' : 'assignments',
            $summary['warnings'],
            $summary['warnings'] === 1 ? 'row' : 'rows',
            $summary['conflicts'],
            $summary['conflicts'] === 1 ? 'row' : 'rows',
        );
    }
}
