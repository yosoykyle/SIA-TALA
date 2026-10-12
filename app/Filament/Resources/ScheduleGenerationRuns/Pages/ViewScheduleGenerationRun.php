<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\SchedulePublicationImpactService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use App\Support\TimetablePresentation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class ViewScheduleGenerationRun extends ViewRecord
{
    use HasTermPlanningContext;

    protected static string $resource = ScheduleGenerationRunResource::class;

    public function termPlanningReturnUrl(): string
    {
        $run = $this->run();
        $versionId = $run->timetableVersions()->where('term_id', $run->term_id)->latest('version')->value('id');

        return TermTimetable::getUrl($versionId
            ? ['termId' => $run->term_id, 'selectedVersionId' => $versionId]
            : ['termId' => $run->term_id, 'selectedRunId' => $run->id]);
    }

    /** @return array<NavigationItem> */
    public function getSubNavigation(): array
    {
        $items = TermPlanningCluster::workspaceNavigation($this->run()->term_id, 'generate');
        foreach ($items as $item) {
            if ($item->getLabel() === TermTimetable::getNavigationLabel()) {
                $item->url($this->termPlanningReturnUrl());
            }
        }

        return $items;
    }

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->acceptCandidateAction(),
            $this->publishScheduleAction(),
            ActionGroup::make([
                $this->rejectCandidateAction(),
                $this->retrySolverRunAction(),
                $this->revisePublishedScheduleAction(),
            ])
                ->label('More timetable actions')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray'),
        ];
    }

    public function getTitle(): string
    {
        return 'Generated Timetable Review';
    }

    public function getSubheading(): string
    {
        return $this->termPlanningContextDescription().(ScheduleGenerationRun::statusOptions()[$this->record->status] ?? $this->record->status);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getInfolistContentComponent(),
            Section::make('Assignment corrections and run history')
                ->description('Open the assignment table to inspect or correct individual meetings. Candidate review, publication and revision histories remain available here.')
                ->schema([$this->getRelationManagersContentComponent()])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public function retrySolverRunAction(): Action
    {
        return Action::make('retrySolverRun')
            ->label('Retry timetable generation')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Retry Timetable Generation')
            ->modalDescription('Retry this same protected request. Earlier technical attempts remain available in Operational Events.')
            ->modalSubmitActionLabel('Retry Generation')
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
            ->label('Revise official timetable')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->modalHeading('Revise official timetable')
            ->modalDescription('Record the approved change and its reason. A draft revision records any affected registrations. Publication requires every affected registration outcome to be resolved and the complete timetable to pass validation; if those checks pass, this action publishes the revision.')
            ->modalSubmitActionLabel('Validate and publish revision')
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
                'authority_reference' => null,
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
                            (string) ($data['authority_reference'] ?? ''),
                        )
                        : app(PublishedScheduleRevisionService::class)->revise(
                            $run,
                            $actor,
                            $changeType,
                            PublishedScheduleRevisionForm::changes($run, $data),
                            $reason,
                            (string) ($data['authority_reference'] ?? ''),
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

    public function publishScheduleAction(): Action
    {
        return Action::make('publishSchedule')
            ->label('Publish Timetable')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Publish Timetable')
            ->modalDescription(fn (): string => $this->publicationConfirmationDescription())
            ->modalSubmitActionLabel('Publish Timetable')
            ->schema([
                TextInput::make('authority_reference')
                    ->label('External timetable sign-off reference')
                    ->required()
                    ->maxLength(255),
                Textarea::make('publication_note')
                    ->label('Publication reason')
                    ->maxLength(2000)
                    ->required(fn (): bool => $this->publicationReasonRequirement() !== null)
                    ->helperText(fn (): string => $this->publicationReasonRequirement()
                        ?? 'Optional for this Optimal candidate because no quality-lowering successor or advisory warning is recorded.'),
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
                        authorityReference: $data['authority_reference'] ?? null,
                    );

                    Notification::make()
                        ->title('Timetable published')
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

    private function acceptCandidateAction(): Action
    {
        return $this->candidateReviewAction('acceptCandidate', 'Accept candidate', 'Accepted', 'success');
    }

    private function rejectCandidateAction(): Action
    {
        return $this->candidateReviewAction('rejectCandidate', 'Reject candidate', 'Rejected', 'danger');
    }

    private function candidateReviewAction(string $name, string $label, string $decision, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription('This attributable review remains non-official. Only a later explicit publication can make an accepted candidate authoritative.')
            ->schema([
                Textarea::make('candidate_review_reason')
                    ->label('Review reason')
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (): bool => $this->canReviewCandidate())
            ->action(function (array $data) use ($decision): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                $service = app(ReviewTimetableCandidate::class);
                $this->record = $decision === 'Accepted'
                    ? $service->accept($this->run(), $actor, (string) $data['candidate_review_reason'])
                    : $service->reject($this->run(), $actor, (string) $data['candidate_review_reason']);

                Notification::make()
                    ->title("Candidate {$decision}")
                    ->body($decision === 'Accepted'
                        ? 'The candidate remains non-official until separately published.'
                        : 'The rejected candidate cannot be published.')
                    ->color($decision === 'Accepted' ? 'success' : 'danger')
                    ->send();
                $this->dispatch('schedule-run-updated');
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

    /** @return array{presentation:array<string, mixed>} */
    public function candidateWeeklyViewData(): array
    {
        $rows = $this->run()->candidateRows()
            ->with([
                'faculty',
                'room',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
            ])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return ['presentation' => TimetablePresentation::forCandidate($this->run(), $rows)];
    }

    private function canReviewCandidate(): bool
    {
        $actor = auth()->user();
        $run = $this->run();

        return $actor instanceof User
            && $run->status === ScheduleGenerationRun::StatusUnderReview
            && ! in_array($run->candidate_state, ['Accepted', 'Rejected', 'Stale', 'Superseded'], true)
            && Gate::forUser($actor)->allows('reviewCandidates', $run);
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
            $reasonRequirement = $this->publicationReasonRequirement();

            return $reasonRequirement === null
                ? $description
                : $description.' Publication reason required: '.$reasonRequirement;
        }

        return $description.' '.sprintf(
            'Full replacement is blocked because the current schedule has %d active student %s across %d affected %s. Use the controlled live-revision workflow instead.',
            $impact->activeOfficialRegistrations(),
            $impact->activeOfficialRegistrations() === 1 ? 'official registration' : 'official registrations',
            $impact->affectedStudents(),
            $impact->affectedStudents() === 1 ? 'student' : 'students',
        );
    }

    private function publicationReasonRequirement(): ?string
    {
        return app(SchedulePublishService::class)->publicationReasonRequirement($this->run());
    }
}
