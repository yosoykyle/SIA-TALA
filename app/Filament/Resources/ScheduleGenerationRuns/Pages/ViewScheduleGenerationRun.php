<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\CandidateScheduleRowReviewService;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\SchedulePublicationImpactService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\CandidateScheduleReviewForm;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
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
            $this->retrySolverRunAction(),
            $this->revisePublishedScheduleAction(),
            $this->manualScheduleOverrideAction(),
            $this->publishScheduleAction(),
        ];
    }

    public function retrySolverRunAction(): Action
    {
        return Action::make('retrySolverRun')
            ->label('Retry Solver Run')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Retry Solver Run')
            ->modalDescription('Requeue this same immutable run. Prior solver attempts remain available in Operational Events.')
            ->modalSubmitActionLabel('Retry Solver Run')
            ->visible(fn (): bool => $this->canRetrySolver())
            ->action(function (): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                $run = $this->run();

                try {
                    $this->record = app(ScheduleSolverRetryService::class)->retry($run, $actor);

                    Notification::make()
                        ->title('Solver run requeued')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Solver retry blocked')
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

    public function revisePublishedScheduleAction(): Action
    {
        return Action::make('revisePublishedSchedule')
            ->label('Revise Published Schedule')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->modalHeading('Revise Published Schedule')
            ->modalDescription('Preview one controlled live revision. TALA locks and revalidates the complete published schedule before applying any change.')
            ->modalSubmitActionLabel('Validate and Apply Revision')
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(fn (): array => [
                'change_type' => null,
                'section_meeting_ids' => [],
                'section_id' => null,
                'replacement_room_id' => null,
                'replacement_faculty_user_id' => null,
                'day_of_week' => null,
                'starts_at' => null,
                'ends_at' => null,
                'reason' => null,
            ])
            ->schema(fn (): array => PublishedScheduleRevisionForm::schema($this->run()))
            ->visible(fn (): bool => $this->canRevisePublishedSchedule())
            ->action(function (array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                $run = $this->run();
                $changeType = (string) ($data['change_type'] ?? '');
                $reason = (string) ($data['reason'] ?? '');

                try {
                    $events = $changeType === ScheduleRevisionEvent::ChangeSectionCancellation
                        ? app(PublishedScheduleRevisionService::class)->cancelSection(
                            $run,
                            PublishedScheduleRevisionForm::section($run, $data),
                            $actor,
                            $reason,
                        )
                        : app(PublishedScheduleRevisionService::class)->revise(
                            $run,
                            $actor,
                            $changeType,
                            PublishedScheduleRevisionForm::changes($run, $data),
                            $reason,
                        );

                    Notification::make()
                        ->title('Published schedule revised')
                        ->body($events->count().' immutable revision '.str('event')->plural($events->count()).' recorded.')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Published schedule revision blocked')
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
                Toggle::make('accept_lower_quality')
                    ->label('Accept lower soft-quality result')
                    ->helperText('Use only when all hard constraints pass but the selected candidate has a lower soft-quality score.'),
                Textarea::make('publication_note')
                    ->label('Publication note')
                    ->maxLength(2000)
                    ->required(fn (Get $get): bool => $this->run()->publicationSummary()['warnings'] > 0
                        || (bool) $get('accept_lower_quality'))
                    ->helperText('Required when accepting advisory warnings or a lower soft-quality result.'),
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

                try {
                    $this->record = app(SchedulePublishService::class)->publish(
                        $run,
                        $publisher,
                        $data['publication_note'] ?? null,
                        (bool) ($data['accept_lower_quality'] ?? false),
                    );

                    Notification::make()
                        ->title('Schedule published')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Schedule publication blocked')
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

    private function canPublish(): bool
    {
        $publisher = auth()->user();
        $run = $this->getRecord();

        return $publisher instanceof User
            && $run instanceof ScheduleGenerationRun
            && Gate::forUser($publisher)->allows('publish', $run)
            && $run->canBePublished();
    }

    private function canRetrySolver(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && Gate::forUser($actor)->allows('retry', $this->run());
    }

    private function canRevisePublishedSchedule(): bool
    {
        $actor = auth()->user();
        $run = $this->getRecord();

        return $actor instanceof User
            && $run instanceof ScheduleGenerationRun
            && $run->isPublished()
            && Gate::forUser($actor)->allows('revise', SectionMeeting::class);
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
        $impact = app(SchedulePublicationImpactService::class)->preview($run);

        $description = sprintf(
            '%d candidate %s, %d warning %s, and %d conflict or violation %s. Impact: %d new, %d changed %s, %d removed, and %d unchanged; %d affected faculty. Publication makes these assignments official and supersedes the prior published version for this term.',
            $summary['assignments'],
            $summary['assignments'] === 1 ? 'assignment' : 'assignments',
            $summary['warnings'],
            $summary['warnings'] === 1 ? 'row' : 'rows',
            $summary['conflicts'],
            $summary['conflicts'] === 1 ? 'row' : 'rows',
            $impact->newAssignments(),
            $impact->changedAssignments(),
            $impact->changedAssignments() === 1 ? 'assignment' : 'assignments',
            $impact->removedAssignments(),
            $impact->unchangedAssignments(),
            $impact->affectedFaculty(),
        );

        if (! $impact->blocksFullReplacement()) {
            return $description;
        }

        return $description.' '.sprintf(
            'Full replacement is blocked because the current schedule has %d active student %s across %d affected %s. Use the controlled live-revision workflow instead.',
            $impact->activeBindings(),
            $impact->activeBindings() === 1 ? 'binding' : 'bindings',
            $impact->affectedStudents(),
            $impact->affectedStudents() === 1 ? 'student' : 'students',
        );
    }
}
