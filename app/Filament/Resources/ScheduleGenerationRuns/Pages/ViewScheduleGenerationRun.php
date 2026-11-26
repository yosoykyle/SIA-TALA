<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Actions\Scheduling\ScheduleReleaseNotificationService;
use App\Actions\Scheduling\ScheduleRevisionNotificationService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\CandidateRowsRelationManager;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\RevisionEventsRelationManager;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Models\OperationalEvent;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\Term;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class ViewScheduleGenerationRun extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = ScheduleGenerationRunResource::class;

    /** @var array<string, mixed> */
    #[Url]
    public array $timetableContext = [];

    public function getBreadcrumb(): string
    {
        return 'Solver report';
    }

    /** @return array<int|string, string> */
    public function getBreadcrumbs(): array
    {
        if (! TermPlanningWorkbench::canAccess()) {
            return parent::getBreadcrumbs();
        }

        $run = $this->run();
        $termId = $this->termId ?? $run->term_id;

        return [
            ...(auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? [AcademicApprovals::getUrl(['termId' => $termId]) => 'Academic Oversight'] : []),
            TermOverview::getUrl(['termId' => $termId]) => 'Term Planning',
            $this->termPlanningReturnUrl() => 'Timetable',
            $this->getBreadcrumb(),
        ];
    }

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-planning-source-detail'];
    }

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
            ...$context,
            'termId' => $run->term_id,
            ...($versionId ? ['selectedVersionId' => $versionId] : ['selectedRunId' => $run->id]),
        ]);
    }

    /** @return array<NavigationItem> */
    public function getWorkspaceNavigation(): array
    {
        $items = TermPlanningCluster::workspaceNavigation($this->run()->term_id, 'generate');
        foreach ($items as $item) {
            if ($item->isActive() || $item->getLabel() === TermTimetable::getNavigationLabel()) {
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
            $this->backToTimetableAction(),
            $this->printTimetableAction(),
            $this->retrySolverRunAction(),
            $this->resendFailedEmailsAction(),
        ];
    }

    public function backToTimetableAction(): Action
    {
        return Action::make('backToTimetable')
            ->label('Back to Timetable')
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->url(fn (): string => $this->termPlanningReturnUrl());
    }

    public function printTimetableAction(): Action
    {
        return Action::make('printTimetable')
            ->label('Print timetable')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->url(function (): ?string {
                $version = $this->publishedVersion();

                return $version instanceof PublishedTimetableVersion
                    ? route('timetable.version.print', ['version' => $version])
                    : null;
            }, shouldOpenInNewTab: true)
            ->visible(fn (): bool => $this->publishedVersion() !== null);
    }

    public function retrySolverRunAction(): Action
    {
        return Action::make('retrySolverRun')
            ->label(fn (): string => $this->canRecoverSolver() ? 'Try again after a service fix' : 'Retry solver')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $this->canRecoverSolver() ? 'Try again after a service fix' : 'Retry solver')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription(fn (): string => $this->run()->term->label.' · Attempt #'.$this->run()->id.'. '.($this->canRecoverSolver()
                ? 'After the System Administrator confirms the service fix, queue one new attempt after current planning data passes readiness checks. The failed attempt stays in history. Any original repair change is preserved.'
                : 'Retry this request using the same recorded inputs. Earlier attempts remain in the history.'))
            ->modalSubmitActionLabel(fn (): string => $this->canRecoverSolver() ? 'Queue new attempt' : 'Retry solver')
            ->schema([
                Textarea::make('correction_explanation')
                    ->label('What was fixed?')
                    ->helperText('Record the service correction confirmed by the System Administrator. This explanation stays with the new attempt.')
                    ->required(fn (): bool => $this->canRecoverSolver())
                    ->maxLength(2000)
                    ->visible(fn (): bool => $this->canRecoverSolver()),
            ])
            ->visible(fn (): bool => $this->canRetrySolver() || $this->canRecoverSolver())
            ->action(function (array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User || $this->isTermClosed()) {
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

    public function resendFailedEmailsAction(): Action
    {
        return Action::make('resendFailedEmails')
            ->label(function (): string {
                $count = $this->failedScheduleEmailEvents()->count();

                return $count > 1 ? "Resend failed emails ({$count})" : 'Resend failed emails';
            })
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Resend failed schedule emails')
            ->modalDescription(function (): string {
                $failed = $this->failedScheduleEmailEvents();
                $releaseCount = $failed->where('event_type', OperationalEvent::TypeScheduleReleasedEmail)->count();
                $revisionCount = $failed->where('event_type', OperationalEvent::TypeScheduleRevisionEmail)->count();

                $parts = [];
                if ($releaseCount > 0) {
                    $parts[] = $releaseCount.' timetable release '.str('email')->plural($releaseCount);
                }
                if ($revisionCount > 0) {
                    $parts[] = $revisionCount.' schedule revision '.str('email')->plural($revisionCount);
                }

                return 'Queues new delivery attempts for '.implode(' and ', $parts).' that previously failed for this timetable record.';
            })
            ->modalSubmitActionLabel('Resend failed emails')
            ->visible(fn (): bool => ! $this->isTermClosed()
                && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->failedScheduleEmailEvents()->isNotEmpty())
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar) && ! $this->isTermClosed(), 403);

                $failed = $this->failedScheduleEmailEvents();
                $releaseService = app(ScheduleReleaseNotificationService::class);
                $revisionService = app(ScheduleRevisionNotificationService::class);
                $resent = 0;
                $stillFailed = 0;

                foreach ($failed as $event) {
                    try {
                        $updated = $event->event_type === OperationalEvent::TypeScheduleReleasedEmail
                            ? $releaseService->resend($event, $actor)
                            : $revisionService->resend($event, $actor);

                        if ($updated->status === OperationalEvent::StatusFailed) {
                            $stillFailed++;
                        } else {
                            $resent++;
                        }
                    } catch (ValidationException) {
                        $stillFailed++;
                    }
                }

                if ($resent > 0 && $stillFailed === 0) {
                    Notification::make()
                        ->title('Failed emails queued for resend')
                        ->body("Re-queued {$resent} ".str('email')->plural($resent).'.')
                        ->success()
                        ->send();
                } elseif ($resent > 0) {
                    Notification::make()
                        ->title('Some emails re-queued')
                        ->body("Re-queued {$resent} ".str('email')->plural($resent)."; {$stillFailed} could not be queued (check recipient email addresses).")
                        ->warning()
                        ->persistent()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Could not resend failed emails')
                        ->body('Check that affected recipients have valid email addresses and mail configuration is active.')
                        ->danger()
                        ->persistent()
                        ->send();
                }

                $this->dispatch('schedule-run-updated');
            });
    }

    public function getTitle(): string
    {
        return 'Solver report';
    }

    public function getSubheading(): string
    {
        $run = $this->run();
        $statusLabel = ScheduleGenerationRun::statusOptions()[$run->status] ?? Str::headline((string) $run->status);

        return "Attempt #{$run->id} · {$statusLabel} · Read-only solver verification and placement snapshot";
    }

    public function content(Schema $schema): Schema
    {
        $run = $this->run();
        $componentData = ['ownerRecord' => $run, 'pageClass' => static::class];
        $showRevisionHistory = RevisionEventsRelationManager::canViewForRecord($run, static::class);
        $revisionCount = $showRevisionHistory ? $run->revisionEvents()->count() : 0;

        $tabsNavHtml = fn (): HtmlString => new HtmlString(Blade::render(<<<'BLADE'
            <div class="tala-work-tabs tala-work-tabs--full">
                <x-tala-navigation label="Solver report sections" compact stretch class="tala-work-tabs__nav">
                    <x-filament::tabs.item
                        icon="heroicon-o-shield-check"
                        alpine-active="activeRecordTab === 'summary'"
                        x-bind:aria-current="activeRecordTab === 'summary' ? 'true' : null"
                        x-on:click="activeRecordTab = 'summary'"
                    >
                        Summary &amp; checks
                    </x-filament::tabs.item>
                    <x-filament::tabs.item
                        icon="heroicon-o-calendar-days"
                        :badge="(string) $meetingCount"
                        badge-color="gray"
                        alpine-active="activeRecordTab === 'meetings'"
                        x-bind:aria-current="activeRecordTab === 'meetings' ? 'true' : null"
                        x-on:click="activeRecordTab = 'meetings'"
                    >
                        Recorded meetings
                    </x-filament::tabs.item>
                    @if ($showRevisionHistory)
                        <x-filament::tabs.item
                            icon="heroicon-o-clock"
                            :badge="$revisionCount > 0 ? (string) $revisionCount : null"
                            badge-color="gray"
                            alpine-active="activeRecordTab === 'history'"
                            x-bind:aria-current="activeRecordTab === 'history' ? 'true' : null"
                            x-on:click="activeRecordTab = 'history'"
                        >
                            Revision history
                        </x-filament::tabs.item>
                    @endif
                </x-tala-navigation>
            </div>
        BLADE, [
            'meetingCount' => $this->run()->candidateRows()->count(),
            'revisionCount' => $showRevisionHistory ? $this->run()->revisionEvents()->count() : 0,
            'showRevisionHistory' => $showRevisionHistory,
        ]));

        return $schema->components([
            Group::make([
                Html::make($tabsNavHtml)->columnSpanFull(),
                Group::make([
                    $this->getInfolistContentComponent(),
                ])
                    ->extraAttributes([
                        'id' => 'timetable-run-evidence',
                        'x-show' => "activeRecordTab === 'summary'",
                        'class' => 'tala-run-tab-panel',
                    ])
                    ->columnSpanFull(),
                Group::make([
                    LivewireComponent::make(CandidateRowsRelationManager::class, $componentData)
                        ->key('candidate-meetings-'.$run->id.'-'.$run->status),
                ])
                    ->extraAttributes([
                        'id' => 'timetable-run-meetings',
                        'x-cloak' => '',
                        'x-show' => "activeRecordTab === 'meetings'",
                        'class' => 'tala-guide-review-assignments tala-run-tab-panel',
                    ])
                    ->columnSpanFull(),
                Group::make([
                    $revisionCount > 0
                        ? LivewireComponent::make(RevisionEventsRelationManager::class, $componentData)
                            ->key('term-revisions-'.$run->term_id)
                        : View::make('filament.pages.partials.timetable-revision-empty'),
                ])
                    ->visible($showRevisionHistory)
                    ->extraAttributes([
                        'id' => 'timetable-term-history',
                        'x-cloak' => '',
                        'x-show' => "activeRecordTab === 'history'",
                        'class' => 'tala-run-history tala-run-tab-panel',
                    ])
                    ->columnSpanFull(),
            ])
                ->extraAttributes([
                    'class' => 'tala-run-record-tabs-shell',
                    'x-data' => "{ activeRecordTab: 'summary', syncTabFromHash() { const h = window.location.hash; if (h === '#timetable-run-meetings') this.activeRecordTab = 'meetings'; else if (h === '#timetable-term-history') this.activeRecordTab = 'history'; else if (h === '#timetable-run-evidence' || h === '#timetable-run-summary') this.activeRecordTab = 'summary'; }, init() { this.syncTabFromHash(); } }",
                    'x-on:hashchange.window' => 'syncTabFromHash()',
                ])
                ->columnSpanFull(),
        ]);
    }

    #[On('schedule-run-updated')]
    public function refreshScheduleRun(): void
    {
        $fresh = $this->run()->fresh();

        if ($fresh instanceof ScheduleGenerationRun) {
            $this->record = $fresh;
        }
    }

    private function publishedVersion(): ?PublishedTimetableVersion
    {
        $run = $this->run();

        if (! $run->isPublished() && $run->published_at === null) {
            return null;
        }

        $requestedVersionId = is_numeric($this->timetableContext['selectedVersionId'] ?? null)
            ? (int) $this->timetableContext['selectedVersionId']
            : null;

        if ($requestedVersionId !== null) {
            $requested = $run->timetableVersions()
                ->where('term_id', $run->term_id)
                ->whereKey($requestedVersionId)
                ->first();

            if ($requested instanceof PublishedTimetableVersion) {
                return $requested;
            }
        }

        return $run->timetableVersions()
            ->where('term_id', $run->term_id)
            ->latest('version')
            ->first();
    }

    private function isTermClosed(): bool
    {
        return $this->run()->term?->state === Term::StateClosed;
    }

    private function canRetrySolver(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && ! $this->isTermClosed()
            && Gate::forUser($actor)->allows('retry', $this->run());
    }

    private function canRecoverSolver(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && ! $this->isTermClosed()
            && ! $this->run()->canRetrySolver()
            && Gate::forUser($actor)->allows('recover', $this->run());
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

        return is_string($message) ? $message : 'The solver retry request failed validation.';
    }

    /**
     * @return EloquentCollection<int, OperationalEvent>
     */
    private function failedScheduleEmailEvents(): EloquentCollection
    {
        $run = $this->run();
        $versionIds = $run->timetableVersions()->pluck('id')->all();
        $revisionEventIds = $run->revisionEvents()->pluck('id')->all();

        return OperationalEvent::query()
            ->where('status', OperationalEvent::StatusFailed)
            ->where(function ($query) use ($run, $versionIds, $revisionEventIds): void {
                $query->where(function ($releaseRun) use ($run): void {
                    $releaseRun->where('event_type', OperationalEvent::TypeScheduleReleasedEmail)
                        ->where('related_record_type', ScheduleGenerationRun::class)
                        ->where('related_record_id', $run->id);
                });

                if ($versionIds !== []) {
                    $query->orWhere(function ($releaseVersion) use ($versionIds): void {
                        $releaseVersion->where('event_type', OperationalEvent::TypeScheduleReleasedEmail)
                            ->where('related_record_type', PublishedTimetableVersion::class)
                            ->whereIn('related_record_id', $versionIds);
                    });
                }

                if ($revisionEventIds !== []) {
                    $query->orWhere(function ($revision) use ($revisionEventIds): void {
                        $revision->where('event_type', OperationalEvent::TypeScheduleRevisionEmail)
                            ->where('related_record_type', ScheduleRevisionEvent::class)
                            ->whereIn('related_record_id', $revisionEventIds);
                    });
                }
            })
            ->orderBy('id')
            ->get();
    }
}
