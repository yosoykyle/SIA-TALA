<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\Actions\ExportClassRosterAction;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Schemas\GradeRosterInfolist;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\OperationalEvent;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use RuntimeException;

class ViewGradeRoster extends ViewRecord
{
    protected static string $resource = GradeRosterResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    #[Url]
    public string $viewTab = 'grade-review';

    #[Url(as: 'history')]
    public bool $submissionHistoryOpen = false;

    public int $submissionHistoryPage = 1;

    public function gotoSubmissionHistoryPage(int $page): void
    {
        $this->submissionHistoryPage = max(1, $page);
    }

    /** @var array<string, mixed> */
    #[Url]
    public array $queue = [];

    #[Locked]
    public int $reviewedVersion = 0;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        if ($this->viewTab === 'history') {
            $this->viewTab = 'grade-review';
            $this->submissionHistoryOpen = true;
        }
        if ($this->submissionHistoryOpen && $this->isRegistrar()) {
            $this->defaultAction = 'classActivity';
        }
        $this->queue = $this->queue === [] ? [] : GradesAndCompletion::classQueueParameters($this->queue, $this->viewTab);
        $this->reviewedVersion = (int) $this->record->current_version_number;
    }

    public function getTitle(): string
    {
        return $this->record->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Class roster';
    }

    public function content(Schema $schema): Schema
    {
        if ($this->record->state === GradeRoster::StateReleased && ! $this->isHeadReader()) {
            return $schema->components([$this->getRelationManagersContentComponent()]);
        }

        return parent::content($schema);
    }

    public function getSubheading(): string|HtmlString
    {
        $context = $this->classContext();
        $status = match ($this->record->state) {
            GradeRoster::StateReleased => 'Released class roster',
            GradeRoster::StateReturned => 'Returned to Faculty',
            default => null,
        };

        if (! $this->isRegistrar() || $status === null) {
            return $context;
        }

        $faculty = $this->record->teachingAssignment?->faculty?->getFilamentName() ?? 'Not recorded';
        $statusClass = $this->record->state === GradeRoster::StateReturned ? ' tala-record-class-context--returned' : '';

        return new HtmlString(e($context).'<span class="tala-record-class-context'.$statusClass.'"><strong>'.e($status).'</strong><span>Faculty: '.e($faculty).'</span></span>');
    }

    private function classContext(): string
    {
        return collect([
            $this->record->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
            $this->record->section?->code, $this->record->termOffering?->term?->label,
            $this->record->termOffering?->term?->academicYear?->label,
        ])->filter()->implode(' · ');
    }

    private function isRegistrar(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }

    private function isHeadReader(): bool
    {
        return (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false)
            && ! auth()->user()->hasRole(User::StaffRoleRegistrar);
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->isHeadReader() ? ['class' => 'tala-head-rosters-page'] : parent::getExtraBodyAttributes();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToClassRosters')->label('Back to class rosters')->color('gray')
                ->url(fn (): string => GradeRosterResource::getUrl('index', ['oversightTermId' => $this->oversightTermId]))
                ->visible(fn (): bool => $this->isHeadReader()),
            $this->exportRosterAction(),
            $this->classActivityAction()->visible(fn (): bool => $this->isRegistrar()),
            $this->classDeliveryAction()->visible(fn (): bool => $this->isRegistrar()),
            Action::make('authorizeLateGradeEncoding')
                ->label('Reopen grade entry')
                ->modalHeading('Reopen grade entry')
                ->modalDescription(fn (): string => 'Allow the designated Faculty member to edit and resubmit final results for '.$this->getTitle().' during these dates. This applies only to this class roster; submitted results still require Registrar review before release.')
                ->modalSubmitActionLabel('Reopen grade entry')
                ->schema([
                    DateTimePicker::make('opens_at')
                        ->label('Opens at (Asia/Manila)')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('closes_at')
                        ->label('Closes at (Asia/Manila)')
                        ->seconds(false)
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->maxLength(2000),
                ])
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar)
                    && in_array($this->gradeRoster()->state, [GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true))
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    try {
                        app(AuthorizeLateGradeEncoding::class)->execute(
                            $this->gradeRoster(),
                            LateGradeAuthorization::PeriodFinal,
                            Carbon::parse($data['opens_at']),
                            Carbon::parse($data['closes_at']),
                            (string) $data['reason'],
                            $actor,
                        );
                    } catch (RuntimeException $exception) {
                        Notification::make()->title('Grade entry was not reopened')->body($exception->getMessage())->danger()->send();

                        throw new Halt;
                    }

                    Notification::make()->title('Grade entry reopening recorded')->success()->send();
                }),
        ];
    }

    public function exportRosterAction(): Action
    {
        return ExportClassRosterAction::make('exportRoster')
            ->record(fn (): GradeRoster => $this->gradeRoster())
            ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                && $this->record->rows()->where('is_current_membership', true)->exists());
    }

    public function classActivityAction(): Action
    {
        return Action::make('classActivity')->label('Class activity')->icon('heroicon-o-clock')->color('gray')
            ->slideOver()->modalHeading('Class submission history')->modalWidth('3xl')
            ->modalDescription(fn (): string => $this->getTitle().' · '.$this->classContext())
            ->modalContent(fn (): View => view('filament.admin.grade-rosters.submission-history', [
                'submissionHistory' => GradeRosterInfolist::submissionHistory($this->gradeRoster(), $this->submissionHistoryPage),
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public function classDeliveryAction(): Action
    {
        return Action::make('classDelivery')->label('Delivery')->icon('heroicon-o-envelope')->color('gray')
            ->slideOver()->modalHeading('Teaching assignment and notification delivery')->modalWidth('3xl')
            ->modalDescription(fn (): string => $this->getTitle().' · '.$this->classContext())
            ->modalContent(fn (): View => view('filament.admin.grade-rosters.class-delivery', [
                'roster' => $this->gradeRoster(),
                'notifications' => GradeRosterInfolist::notificationQuery($this->gradeRoster())->latest('occurred_at')->get(),
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public function returnAction(): Action
    {
        return Action::make('return')
            ->label('Return for changes')->color('gray')
            ->modalSubmitActionLabel('Return selected rows')
            ->modalDescription('Choose the students whose results need correction. Other rows stay unchanged; Faculty resubmits the complete class version.')
            ->schema([
                CheckboxList::make('row_ids')->label('Students to return')->required()->searchable()
                    ->options(fn (): array => $this->gradeRoster()->rows()->where('is_current_membership', true)
                        ->with('courseEnrollment.enrollment.studentProfile')->get()->mapWithKeys(function ($row): array {
                            $student = $row->courseEnrollment?->enrollment?->studentProfile;

                            return [$row->id => collect([$student?->student_number, $student?->last_name, $student?->first_name, $row->final_result])->filter()->implode(' · ')];
                        })->all()),
                Textarea::make('reason')->required()->minLength(10)->maxLength(1000),
            ])
            ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->gradeRoster()->state === GradeRoster::StateSubmitted)
            ->action(function (array $data): void {
                try {
                    app(ReturnGradeRoster::class)->execute($this->gradeRoster(), auth()->user(), (string) $data['reason'], array_map('intval', $data['row_ids']), $this->reviewedVersion);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Roster was not returned')->body($exception->getMessage())->danger()->send();

                    throw new Halt;
                }
                $this->redirect(GradeRosterResource::getUrl('view', ['record' => $this->record, 'viewTab' => $this->viewTab, 'queue' => GradesAndCompletion::classQueueParameters($this->queue, $this->viewTab)]), navigate: true);
                Notification::make()->title('Grade roster returned')->warning()->send();
            });
    }

    public function postAndReleaseAction(): Action
    {
        return Action::make('postAndRelease')
            ->label('Release class grades')->color('primary')
            ->modalDescription('Release the complete current class version to students. Affected active enrollment cases will be sent to the Registrar for review; subjects are not changed automatically.')
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Release class grades')
            ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->gradeRoster()->state === GradeRoster::StateSubmitted)
            ->action(function (): void {
                try {
                    app(PostAndReleaseGradeRoster::class)->execute($this->gradeRoster(), auth()->user(), expectedVersion: $this->reviewedVersion);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Roster was not released')->body($exception->getMessage())->danger()->send();

                    throw new Halt;
                }
                $this->redirect(GradeRosterResource::getUrl('view', ['record' => $this->record, 'viewTab' => $this->viewTab, 'queue' => GradesAndCompletion::classQueueParameters($this->queue, $this->viewTab)]), navigate: true);
                Notification::make()->title('Grade roster posted and released')->success()->send();
            });
    }

    public function resendAcademicNoticeAction(): Action
    {
        return Action::make('resendAcademicNotice')->label('Retry notification')->color('gray')
            ->modalSubmitActionLabel('Retry notification')
            ->modalDescription('Retry one failed or pending notice. This does not change grades or release results.')
            ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false) && $this->retryableNotifications()->exists())
            ->schema([Select::make('notification_id')->label('Failed or pending notice')->placeholder('Choose the notice to retry')->required()
                ->options(fn (): array => $this->retryableNotifications()->get()->mapWithKeys(fn (OperationalEvent $event): array => [$event->id => str($event->event_type)->replace('_', ' ')->ucfirst().' · '.str($event->status)->lower()->headline()])->all())])
            ->action(function (array $data): void {
                $event = GradeRosterInfolist::notificationQuery($this->gradeRoster())->find((int) $data['notification_id']);
                if (! $event) {
                    Notification::make()->title('Notice is no longer available')->warning()->send();
                    throw new Halt;
                }
                try {
                    app(AcademicRecordNotificationService::class)->resend($event, auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->title('Unable to retry notice')->body($exception->getMessage())->warning()->send();
                    throw new Halt;
                }
                Notification::make()->title('Notification retry queued')->success()->send();
            });
    }

    public function getBreadcrumbs(): array
    {
        if ($this->isHeadReader()) {
            return [
                AcademicApprovals::getUrl(['termId' => $this->oversightTermId]) => 'Academic Oversight',
                GradeRosterResource::getUrl('index', ['oversightTermId' => $this->oversightTermId]) => 'Class rosters',
                $this->record->section?->code ?? 'Class roster',
            ];
        }

        return [GradesAndCompletion::getUrl(['viewTab' => $this->viewTab, ...($this->queue === [] ? [] : GradesAndCompletion::classQueueParameters($this->queue, $this->viewTab))]) => GradesAndCompletion::tabs()[$this->viewTab] ?? 'Grade review',
            $this->record->section?->code ?? 'Class roster'];
    }

    private function retryableNotifications(): Builder
    {
        return app(AcademicRecordNotificationService::class)->retryableQuery(GradeRosterInfolist::notificationQuery($this->gradeRoster()));
    }

    private function gradeRoster(): GradeRoster
    {
        return GradeRoster::query()->findOrFail($this->record->getKey());
    }
}
