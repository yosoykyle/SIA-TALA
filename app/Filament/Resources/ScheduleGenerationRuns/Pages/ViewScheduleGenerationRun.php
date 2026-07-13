<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\CandidateScheduleRowReviewService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\CandidateScheduleReviewForm;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class ViewScheduleGenerationRun extends ViewRecord
{
    protected static string $resource = ScheduleGenerationRunResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->manualScheduleOverrideAction(),
            $this->publishScheduleAction(),
        ];
    }

    public function manualScheduleOverrideAction(): Action
    {
        return Action::make('manualScheduleOverride')
            ->label('Manual Schedule Override')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('warning')
            ->modalHeading('Manual Schedule Override')
            ->modalDescription('Provide one complete replacement assignment set. TALA saves nothing unless every current hard constraint passes.')
            ->modalSubmitActionLabel('Validate Complete Schedule')
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(fn (): array => [
                'assignments' => CandidateScheduleReviewForm::replacementRows($this->run()),
                'override_authority' => null,
                'override_reason' => null,
            ])
            ->schema(fn (): array => CandidateScheduleReviewForm::manualOverrideSchema($this->run()))
            ->visible(fn (): bool => $this->canManualOverride())
            ->action(function (array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                $run = $this->run();
                $assignments = $data['assignments'] ?? null;

                if (! is_array($assignments)) {
                    throw ValidationException::withMessages([
                        'assignments' => 'A complete replacement assignment set is required.',
                    ]);
                }

                try {
                    $this->record = app(CandidateScheduleRowReviewService::class)->replace(
                        $run,
                        array_values($assignments),
                        $actor,
                        (string) ($data['override_authority'] ?? ''),
                        (string) ($data['override_reason'] ?? ''),
                    );

                    Notification::make()
                        ->title('Manual Schedule Override accepted')
                        ->body('The complete replacement schedule passed current hard-constraint validation.')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Manual Schedule Override blocked')
                        ->body($this->validationMessage($exception))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
                } finally {
                    $fresh = $run->fresh();

                    if ($fresh instanceof ScheduleGenerationRun) {
                        $this->record = $fresh;
                    }

                    $this->dispatch('schedule-run-updated');
                }
            });
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

    private function canManualOverride(): bool
    {
        $actor = auth()->user();
        $run = $this->getRecord();

        return $actor instanceof User
            && $run instanceof ScheduleGenerationRun
            && in_array($run->status, [
                ScheduleGenerationRun::StatusUnderReview,
                ScheduleGenerationRun::StatusBlocked,
                ScheduleGenerationRun::StatusFailed,
            ], true)
            && Gate::forUser($actor)->allows('reviewCandidates', $run);
    }

    #[On('schedule-run-updated')]
    public function refreshScheduleRun(): void
    {
        $fresh = $this->run()->fresh();

        if ($fresh instanceof ScheduleGenerationRun) {
            $this->record = $fresh;
        }
    }

    private function run(): ScheduleGenerationRun
    {
        $run = $this->getRecord();

        if (! $run instanceof ScheduleGenerationRun) {
            abort(404);
        }

        return $run;
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : 'The complete replacement schedule failed current hard-constraint validation.';
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
