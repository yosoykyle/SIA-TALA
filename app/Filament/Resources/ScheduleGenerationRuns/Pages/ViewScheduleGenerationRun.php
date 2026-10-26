<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\SchedulePublicationImpactService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\CandidateRowsRelationManager;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\RevisionEventsRelationManager;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\ScheduleGenerationRunInfolist;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use App\Support\TimetablePresentation;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class ViewScheduleGenerationRun extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = ScheduleGenerationRunResource::class;

    public function getBreadcrumb(): string
    {
        return $this->getTitle();
    }

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-planning-source-detail'];
    }

    /** @var array<string, mixed> */
    #[Url]
    public array $timetableContext = [];

    public function termPlanningReturnUrl(): string
    {
        $run = $this->run();
        $versionId = $run->timetableVersions()->where('term_id', $run->term_id)->latest('version')->value('id');

        $validator = validator($this->timetableContext, [
            'timetableView' => ['sometimes', 'in:whole,section,faculty,room'],
            'timetableFormat' => ['sometimes', 'in:week,list'],
            'viewFilter' => ['nullable', 'string', 'max:255'],
            'programFilter' => ['nullable', 'string', 'max:255'],
            'yearFilter' => ['nullable', 'string', 'max:255'],
            'tableSearch' => ['nullable', 'string', 'max:255'],
            'selectedVersionId' => ['nullable', 'integer', 'min:1'],
        ]);
        $context = $validator->passes() ? $validator->validated() : [];
        $requestedVersionId = $context['selectedVersionId'] ?? null;
        unset($context['selectedVersionId']);
        if ($requestedVersionId !== null) {
            $versionId = $run->timetableVersions()->where('term_id', $run->term_id)->whereKey($requestedVersionId)->value('id') ?? $versionId;
        }

        return TermTimetable::getUrl([
            ...$context, 'termId' => $run->term_id,
            ...($versionId ? ['selectedVersionId' => $versionId] : ['selectedRunId' => $run->id]),
        ]);
    }

    /** @return array<NavigationItem> */
    public function getWorkspaceNavigation(): array
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
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->acceptCandidateAction(),
            $this->publishScheduleAction(),
            $this->rejectCandidateAction(),
            $this->retrySolverRunAction(),
            $this->revisePublishedScheduleAction(),
            $this->cancelPublishedClassAction(),
        ];
    }

    public function getTitle(): string
    {
        return match ($this->run()->status) {
            ScheduleGenerationRun::StatusPublished => 'Official timetable record',
            ScheduleGenerationRun::StatusSuperseded => $this->run()->published_at !== null ? 'Historical timetable record' : 'Superseded candidate',
            ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching => 'Timetable in progress',
            ScheduleGenerationRun::StatusUnderReview => 'Review timetable candidate',
            default => 'Timetable generation result',
        };
    }

    public function getSubheading(): string
    {
        return ScheduleGenerationRun::statusOptions()[$this->record->status] ?? $this->record->status;
    }

    public function content(Schema $schema): Schema
    {
        $run = $this->run();
        $published = $run->isPublished() || $run->published_at !== null;
        $componentData = ['ownerRecord' => $run, 'pageClass' => static::class];
        $meetings = Section::make($published ? 'Original candidate meetings' : 'Meetings to review')
            ->key('run-meetings-'.$run->id)
            ->description($published
                ? 'These assignments belong to the original generation record. Open the official timetable to see the issued version.'
                : 'Inspect a meeting, or use Changes to adjust it. Review the complete candidate before accepting it.')
            ->schema(Schema::make()->components([
                LivewireComponent::make(CandidateRowsRelationManager::class, $componentData)
                    ->key('candidate-meetings-'.$run->id.'-'.$run->status),
            ])->deferLoading($published))
            ->contained($published)
            ->collapsible($published)
            ->collapsed($published)
            ->extraAttributes(['class' => 'tala-guide-review-assignments', 'id' => 'timetable-run-meetings'])
            ->columnSpanFull();
        $history = Section::make('Term revision history')
            ->description('Official meeting changes across this Term, including changes recorded from other attempts. This history is separate from candidate adjustments.')
            ->schema([
                $run->revisionEvents()->exists()
                    ? LivewireComponent::make(RevisionEventsRelationManager::class, $componentData)
                        ->key('term-revisions-'.$run->term_id)
                    : View::make('filament.pages.partials.timetable-revision-empty'),
            ])
            ->visible(fn (): bool => RevisionEventsRelationManager::canViewForRecord($run, static::class))
            ->collapsible()
            ->collapsed(! $published)
            ->extraAttributes(['class' => 'tala-run-history', 'id' => 'timetable-term-history'])
            ->columnSpanFull();

        return $schema->components([
            ScheduleGenerationRunInfolist::reviewSummary($run),
            ...($published ? [$history, $meetings] : [$meetings]),
            $this->getInfolistContentComponent(),
            ...($published ? [] : [$history]),
        ]);
    }

    public function retrySolverRunAction(): Action
    {
        return Action::make('retrySolverRun')
            ->label(fn (): string => $this->canRecoverSolver() ? 'Try again after a service fix' : 'Retry timetable generation')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $this->canRecoverSolver() ? 'Try again after a service fix' : 'Retry generation')

            ->modalDescription(fn (): string => $this->run()->term->label.' · Attempt #'.$this->run()->id.'. '.($this->canRecoverSolver()
                ? 'After the System Administrator confirms the service fix, queue one new attempt after current planning data passes readiness checks. The failed attempt stays in history. Any original repair change is preserved.'
                : 'Retry this request using the same recorded inputs. Earlier attempts remain in the history.'))
            ->modalSubmitActionLabel(fn (): string => $this->canRecoverSolver() ? 'Queue new attempt' : 'Retry generation')
            ->schema([
                Textarea::make('correction_explanation')->label('What was fixed?')
                    ->helperText('Record the service correction confirmed by the System Administrator. This explanation stays with the new attempt.')
                    ->required(fn (): bool => $this->canRecoverSolver())->maxLength(2000)
                    ->visible(fn (): bool => $this->canRecoverSolver()),
            ])
            ->visible(fn (): bool => $this->canRetrySolver() || $this->canRecoverSolver())
            ->action(function (array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                $run = $this->run();

                try {
                    $this->record = $this->canRecoverSolver()
                        ? app(ScheduleSolverRetryService::class)->recover($run, $actor, (string) ($data['correction_explanation'] ?? ''))
                        : app(ScheduleSolverRetryService::class)->retry($run, $actor);

                    Notification::make()
                        ->title($this->record->id !== $run->id ? 'New attempt queued' : 'Solver run requeued')
                        ->success()
                        ->send();

                    if ($this->record->id !== $run->id) {
                        $this->redirect(ScheduleGenerationRunResource::getUrl('view', ['record' => $this->record, 'termId' => $run->term_id]));
                    }
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Solver retry blocked')
                        ->body($this->validationMessage($exception))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
                } finally {
                    $fresh = $this->record->fresh();

                    if ($fresh instanceof ScheduleGenerationRun) {
                        $this->record = $fresh;
                    }

                    $this->dispatch('schedule-run-updated');
                }
            });
    }

    public function revisePublishedScheduleAction(): Action
    {
        return $this->publishedRevisionAction(false);
    }

    public function cancelPublishedClassAction(): Action
    {
        return $this->publishedRevisionAction(true);
    }

    private function publishedRevisionAction(bool $cancelClass): Action
    {
        return Action::make($cancelClass ? 'cancelPublishedClass' : 'revisePublishedSchedule')

            ->label($cancelClass ? 'Cancel a class' : 'Change meetings')
            ->icon($cancelClass ? Heroicon::OutlinedXCircle : Heroicon::OutlinedPencilSquare)
            ->color($cancelClass ? 'danger' : 'gray')
            ->modalHeading($cancelClass ? 'Cancel a class' : 'Change meetings')
            ->modalDescription($cancelClass
                ? 'This cancels the whole class and all its official meetings. Affected registration outcomes must be resolved before publication; earlier versions stay in history.'
                : 'Change the selected meetings, review the impact, then publish a new version. The current timetable stays in use until publication succeeds.')
            ->modalSubmitActionLabel($cancelClass ? 'Validate and cancel class' : 'Validate and publish revision')
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->color($cancelClass ? 'danger' : 'primary')
                ->disabled(function () use ($cancelClass): bool {
                    $data = $this->getMountedAction()?->getRawData() ?? [];

                    return blank($data['change_type'] ?? null)
                        || blank($data[$cancelClass ? 'section_id' : 'section_meeting_ids'] ?? null);
                }))
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(fn (array $arguments): array => [
                ...$this->revisionTarget($arguments, $cancelClass),
                'change_type' => $cancelClass ? ScheduleRevisionEvent::ChangeSectionCancellation : null,
                'replacement_room_id' => null,
                'replacement_faculty_user_id' => null,
                'day_of_week' => null,
                'starts_at' => null,
                'ends_at' => null,
                'reason' => null,
                'authority_reference' => null,
            ])
            ->schema(fn (): array => PublishedScheduleRevisionForm::schema($this->run(), $cancelClass))
            ->visible(fn (): bool => $this->canRevisePublishedSchedule())
            ->action(function (array $data) use ($cancelClass): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                $run = $this->run();
                $changeType = $cancelClass ? ScheduleRevisionEvent::ChangeSectionCancellation : (string) ($data['change_type'] ?? '');
                $reason = (string) ($data['reason'] ?? '');

                if (! $cancelClass && $changeType === ScheduleRevisionEvent::ChangeSectionCancellation) {
                    throw ValidationException::withMessages(['change_type' => 'Use Cancel a class to cancel the whole class and its meetings.']);
                }

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
                    $this->redirect(TermTimetable::getUrl([
                        'termId' => $run->term_id,
                        'timetableState' => 'official',
                    ]));
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

    /** @param array<string, mixed> $arguments
     * @return array{section_meeting_ids: list<int>, section_id: int|null}
     */
    private function revisionTarget(array $arguments, bool $cancelClass): array
    {
        if (! array_key_exists('meeting', $arguments)) {
            return ['section_meeting_ids' => [], 'section_id' => null];
        }

        abort_unless($this->canRevisePublishedSchedule(), 403);
        $meetingId = filter_var($arguments['meeting'], FILTER_VALIDATE_INT);
        abort_unless($meetingId !== false && $meetingId > 0, 404);
        $meeting = SectionMeeting::query()->where('schedule_run_id', $this->run()->id)
            ->where('state', SectionMeeting::StateActive)
            ->with('schedulingDemand.sectionDeliveryGroup')
            ->findOrFail($meetingId);

        return [
            'section_meeting_ids' => $cancelClass ? [] : [(int) $meeting->id],
            'section_id' => $cancelClass ? (int) $meeting->schedulingDemand->sectionDeliveryGroup->section_id : null,
        ];
    }

    public function publishScheduleAction(): Action
    {
        return Action::make('publishSchedule')
            ->label('Publish official timetable')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Publish official timetable')

            ->modalDescription(fn (): string => $this->run()->term->label.' / Attempt #'.$this->run()->id.'. '.$this->publicationConfirmationDescription())
            ->modalSubmitActionLabel('Publish official timetable')
            ->schema([
                TextInput::make('authority_reference')
                    ->label('Approval reference (optional)')
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
        return $this->candidateReviewAction('acceptCandidate', 'Accept timetable', 'Accepted', 'primary');
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

            ->modalSubmitActionLabel($label)
            ->modalIcon($decision === 'Accepted' ? Heroicon::OutlinedCheck : Heroicon::OutlinedXMark)
            ->modalDescription(fn (): string => $this->run()->term->label.' · Attempt #'.$this->run()->id.'. '.($decision === 'Accepted'
                ? 'Record why this candidate is accepted. It becomes official only after publication.'
                : 'Record why this candidate is rejected. A rejected candidate cannot be published; the current official timetable stays in use.'))
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

    private function canRecoverSolver(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && ! $this->run()->canRetrySolver()
            && Gate::forUser($actor)->allows('recover', $this->run());
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
