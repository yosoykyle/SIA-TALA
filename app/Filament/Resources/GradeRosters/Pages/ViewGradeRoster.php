<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\Actions\ExportClassRosterAction;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Schemas\GradeRosterInfolist;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\OperationalEvent;
use App\Models\Section;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use RuntimeException;

class ViewGradeRoster extends ViewRecord
{
    use HasAcademicOversightReturn;

    protected static string $resource = GradeRosterResource::class;

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

    /** @var array<string, mixed> */
    #[Url]
    public array $returnFilters = [];

    #[Url]
    public ?string $returnSearch = null;

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
        return $this->isRegistrarActor();
    }

    private function isHeadReader(): bool
    {
        return $this->isAcademicHeadReader();
    }

    protected function classRostersReturnUrl(): string
    {
        return GradeRosterResource::getUrl('index', array_filter([
            'oversightTermId' => $this->resolvedOversightTermId(),
            'filters' => $this->returnFilters !== [] ? $this->returnFilters : null,
            'search' => filled($this->returnSearch) ? $this->returnSearch : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->isHeadReader() ? ['class' => 'tala-head-rosters-page'] : parent::getExtraBodyAttributes();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToClassRosters')->label('Back to class rosters')->color('gray')
                ->url(fn (): string => $this->classRostersReturnUrl())
                ->visible(fn (): bool => $this->isHeadReader()),
            $this->exportRosterAction(),
            $this->classActivityAction()->visible(fn (): bool => $this->isRegistrar())->authorize(fn (): bool => $this->isRegistrar()),
            $this->classDeliveryAction()->visible(fn (): bool => $this->isRegistrar())->authorize(fn (): bool => $this->isRegistrar()),
            $this->reassignFacultyAction(),
            Action::make('authorizeLateGradeEncoding')
                ->label('Reopen grade entry')
                ->modalHeading('Reopen grade entry')
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalDescription(fn (): string => 'Allow the designated Faculty member to edit and resubmit final results for '.$this->getTitle().' during these dates. This applies only to this class roster; submitted results still require Registrar review before release.')
                ->modalSubmitActionLabel('Reopen grade entry')
                ->schema([
                    DateTimePicker::make('opens_at')
                        ->label('Opens at')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('closes_at')
                        ->label('Closes at')
                        ->seconds(false)
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->isRegistrar()
                    && in_array($this->gradeRoster()->state, [GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true))
                ->authorize(fn (): bool => $this->isRegistrar())
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
            ->visible(fn (): bool => $this->isRegistrar()
                && $this->record->rows()->where('is_current_membership', true)->exists())
            ->authorize(fn (): bool => $this->isRegistrar());
    }

    public function classActivityAction(): Action
    {
        return Action::make('classActivity')->label('Class activity')->icon('heroicon-o-clock')->color('gray')
            ->slideOver()->modalHeading('Class submission history')->modalWidth(Width::ThreeExtraLarge)->stickyModalHeader()->stickyModalFooter()
            ->modalDescription(fn (): string => $this->getTitle().' · '.$this->classContext())
            ->modalContent(fn (): View => view('filament.admin.grade-rosters.submission-history', [
                'submissionHistory' => GradeRosterInfolist::submissionHistory($this->gradeRoster(), $this->submissionHistoryPage),
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public function classDeliveryAction(): Action
    {
        return Action::make('classDelivery')->label('Delivery')->icon('heroicon-o-envelope')->color('gray')
            ->slideOver()->modalHeading('Teaching assignment and notification delivery')->modalWidth(Width::ThreeExtraLarge)->stickyModalHeader()->stickyModalFooter()
            ->modalDescription(fn (): string => $this->getTitle().' · '.$this->classContext())
            ->modalContent(fn (): View => view('filament.admin.grade-rosters.class-delivery', [
                'roster' => $this->gradeRoster(),
                'notifications' => GradeRosterInfolist::notificationQuery($this->gradeRoster())->latest('occurred_at')->get(),
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public function reassignFacultyAction(): Action
    {
        return Action::make('reassignFaculty')
            ->label('Reassign faculty')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->modalHeading('Reassign faculty')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription(fn (): string => 'Transfer designated class grades ownership for '.$this->getTitle().' ('.$this->classContext().'). Any unsubmitted or submitted version is returned to Draft for the newly designated faculty member.')
            ->modalSubmitActionLabel('Reassign faculty')
            ->visible(fn (): bool => $this->isRegistrar() && $this->gradeRoster()->state !== GradeRoster::StateReleased)
            ->authorize(fn (): bool => $this->isRegistrar())
            ->schema([
                Select::make('faculty_user_id')
                    ->label('Replacement faculty')
                    ->options(fn (): array => $this->reassignableFacultyOptions())
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('co_faculty_user_id')
                    ->label('Co-faculty (optional)')
                    ->helperText('Optionally add a co-faculty member for read-only class list visibility.')
                    ->options(fn (): array => $this->reassignableFacultyOptions())
                    ->searchable()
                    ->preload(),
                Checkbox::make('add_co_faculty')
                    ->label('Keep outgoing designated faculty as co-faculty')
                    ->helperText('Retains read-only class list access for the previous designated faculty member.')
                    ->default(false),
                Textarea::make('authority_reference')
                    ->label('Approval note or memo (optional)')
                    ->rows(2)
                    ->maxLength(255),
                Textarea::make('reason')
                    ->label('Reassignment reason')
                    ->required()
                    ->maxLength(2000),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                $roster = $this->gradeRoster();

                if ($roster->state === GradeRoster::StateReleased) {
                    Notification::make()
                        ->title('Faculty was not reassigned')
                        ->body('Released class grades cannot be reassigned.')
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                $section = $roster->section;

                if (! $section instanceof Section) {
                    Notification::make()
                        ->title('Faculty was not reassigned')
                        ->body('The class section for this roster is unavailable.')
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                $faculty = User::query()->find((int) ($data['faculty_user_id'] ?? 0));

                if (! $faculty instanceof User) {
                    Notification::make()
                        ->title('Faculty was not reassigned')
                        ->body('Select a valid replacement faculty member.')
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                $previousFacultyId = $roster->faculty_user_id ? (int) $roster->faculty_user_id : null;
                $reason = trim((string) ($data['reason'] ?? ''));
                $authority = filled($data['authority_reference'] ?? null)
                    ? trim((string) $data['authority_reference'])
                    : $reason;
                $coFaculty = filled($data['co_faculty_user_id'] ?? null)
                    ? User::query()->find((int) $data['co_faculty_user_id'])
                    : null;
                $keepOutgoingAsCoFaculty = (bool) ($data['add_co_faculty'] ?? false);

                try {
                    app(ManageTeachingAssignment::class)->designate($section, $faculty, $actor, $authority);

                    if ($coFaculty instanceof User && (int) $coFaculty->id !== (int) $faculty->id) {
                        app(ManageTeachingAssignment::class)->addCoFaculty($section, $coFaculty, $actor, $authority);
                    }

                    if ($keepOutgoingAsCoFaculty && $previousFacultyId !== null && $previousFacultyId !== (int) $faculty->id) {
                        $outgoingFaculty = User::query()->find($previousFacultyId);

                        if ($outgoingFaculty instanceof User) {
                            app(ManageTeachingAssignment::class)->addCoFaculty($section, $outgoingFaculty, $actor, $authority);
                        }
                    }
                } catch (RuntimeException|AuthorizationException $exception) {
                    Notification::make()
                        ->title('Faculty was not reassigned')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                $timestamp = now()->toDateTimeString();
                DB::table('activity_log')->insert([
                    'log_name' => 'grades',
                    'description' => 'Grade roster faculty reassigned.',
                    'subject_type' => GradeRoster::class,
                    'subject_id' => $roster->id,
                    'event' => 'grade_roster_faculty_reassigned',
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'properties' => json_encode([
                        'grade_roster_id' => (int) $roster->id,
                        'section_id' => (int) $section->id,
                        'previous_faculty_user_id' => $previousFacultyId,
                        'replacement_faculty_user_id' => (int) $faculty->id,
                        'co_faculty_user_id' => $coFaculty?->id,
                        'keep_outgoing_as_co_faculty' => $keepOutgoingAsCoFaculty,
                        'authority_reference' => $authority,
                        'reason' => $reason,
                        'actor_user_id' => (int) $actor->id,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $this->record = $this->gradeRoster()->fresh([
                    'termOffering.term.academicYear',
                    'termOffering.curriculumEntry.courseSpecification.course',
                    'section',
                    'teachingAssignment.faculty',
                ]);
                $this->reviewedVersion = (int) $this->record->current_version_number;

                Notification::make()
                    ->title('Faculty reassigned')
                    ->body('Designated class grades ownership transferred to '.$faculty->getFilamentName().'.')
                    ->success()
                    ->send();
            });
    }

    /** @return array<int, string> */
    private function reassignableFacultyOptions(): array
    {
        return User::query()
            ->role(User::StaffRoleFaculty)
            ->where('status', User::StatusActive)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [(int) $user->id => $user->getFilamentName()])
            ->all();
    }

    public function returnAction(): Action
    {
        return Action::make('return')
            ->label('Return for changes')->color('gray')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Return selected rows')
            ->modalDescription('Choose the students whose results need correction. Other rows stay unchanged; Faculty resubmits the complete class roster.')
            ->schema([
                CheckboxList::make('row_ids')->label('Students to return')->required()->searchable()
                    ->options(fn (): array => $this->gradeRoster()->rows()->where('is_current_membership', true)
                        ->with('courseEnrollment.enrollment.studentProfile')->get()->mapWithKeys(function ($row): array {
                            $student = $row->courseEnrollment?->enrollment?->studentProfile;

                            return [$row->id => collect([$student?->student_number, $student?->last_name, $student?->first_name, $row->final_result])->filter()->implode(' · ')];
                        })->all()),
                Textarea::make('reason')->required()->minLength(10)->maxLength(1000),
            ])
            ->visible(fn (): bool => $this->isRegistrar() && $this->gradeRoster()->state === GradeRoster::StateSubmitted)
            ->authorize(fn (): bool => $this->isRegistrar())
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
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Release the complete class grades to students. Affected active enrollment cases will be sent to the Registrar for review; subjects are not changed automatically.')
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Release class grades')
            ->visible(fn (): bool => $this->isRegistrar() && $this->gradeRoster()->state === GradeRoster::StateSubmitted)
            ->authorize(fn (): bool => $this->isRegistrar())
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
            ->visible(fn (): bool => $this->isRegistrar() && $this->retryableNotifications()->exists())
            ->authorize(fn (): bool => $this->isRegistrar())
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
                $this->oversightReturnUrl() => 'Academic Oversight',
                $this->classRostersReturnUrl() => 'Class rosters',
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
