<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class TermClassProposal extends TermPlanningWorkbench
{
    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $slug = 'class-proposal';

    protected static ?string $title = 'Prepare Term classes';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $planningTab = 'classes';

    protected string $view = 'filament.pages.term-class-proposal';

    #[Locked]
    public string $proposalSource = '';

    /** @var array<string, mixed> */
    public array $proposal = [];

    /** @var array<string, mixed>|null */
    private ?array $proposalPreview = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar);
    }

    public function mount(): void
    {
        parent::mount();
        abort_unless($this->termId, 404);
        $preview = $this->currentPreview();
        $this->proposalSource = $preview['token'];
        $this->form->fill(['classes' => array_values(array_map(fn (array $row): array => [...$row, 'action' => 'keep'], array_filter($preview['classes'], fn (array $row): bool => filled($row['section_id'] ?? null))))]);
    }

    /** @return array<string, mixed> */
    private function currentPreview(): array
    {
        return $this->proposalPreview ??= app(PrepareTermClassProposal::class)->preview($this->selectedTerm());
    }

    public function form(Schema $schema): Schema
    {
        $catalogRows = collect($this->currentPreview()['catalog'])->keyBy('id');
        $catalog = $catalogRows->pluck('label', 'id')->all();
        $cohorts = $this->currentPreview()['cohorts']->mapWithKeys(fn ($cohort): array => [$cohort->id => $cohort->reference.' · '.$cohort->confirmed_count.' confirmed learners'])->all();

        return $schema->statePath('proposal')->components([
            Repeater::make('classes')->label('Term class decisions')->defaultItems(0)->reorderable(false)->collapsible()->collapsed()
                ->itemLabel(function (array $state) use ($catalogRows): HtmlString|string {
                    $course = $catalogRows->get((int) ($state['entry_id'] ?? 0));
                    $decision = match ($state['action'] ?? 'skip') {
                        'confirm' => 'Prepare class', 'keep' => ($state['is_confirmed'] ?? false) ? 'Keep confirmed' : 'Keep draft',
                        'cancel' => 'Cancel class', default => 'No class to add',
                    };

                    return $course
                        ? new HtmlString('<span class="tala-proposal-item-title"><strong>'.e($course['course_title'].' · '.$course['course_code']).'</strong><small>'.e($course['program'].' · '.$course['year_level'].' · '.$decision).'</small></span>')
                        : 'Additional class · '.$decision;
                })
                ->extraAttributes(['data-class-proposal' => ''])
                ->addable(false)->deletable(false)
                ->collapseAllAction(fn (Action $action): Action => $action->hidden())
                ->expandAllAction(fn (Action $action): Action => $action->hidden())
                ->schema([
                    Hidden::make('is_catalog')->default(false),
                    Hidden::make('entry_id')->visible(fn (Get $get): bool => (bool) $get('is_catalog')),
                    Placeholder::make('course_label')->label('Course')
                        ->content(function (Get $get) use ($catalogRows): HtmlString|string {
                            $course = $catalogRows->get((int) $get('entry_id'));

                            if (! $course) {
                                return 'Choose a course';
                            }

                            $additionalOnly = $course['term_type'] !== $this->selectedTerm()->type;
                            $curriculum = $course['program'].' · '.$course['year_level'].' · '.$course['curriculum_version'];

                            if ($additionalOnly) {
                                $curriculum .= ' · Other Term type; additional approval required';
                            }

                            $meetings = $course['meetings'] ?: 'Meeting definition needs attention';

                            return new HtmlString('<span class="tala-proposal-course-title"><strong>'.e($course['course_title']).' <span class="tala-proposal-course-code">'.e($course['course_code']).'</span></strong><span>'.e($curriculum).'</span><span>'.e($meetings).' · '.$course['existing'].' existing classes</span></span>');
                        })
                        ->visible(fn (Get $get): bool => (bool) $get('is_catalog')),
                    Select::make('entry_id')->label('Course')->options($catalog)->searchable()
                        ->required(fn (Get $get): bool => $get('action') === 'confirm')->dehydrated()
                        ->visible(fn (Get $get): bool => ! $get('is_catalog')),
                    Hidden::make('section_id'),
                    Hidden::make('is_confirmed'),
                    Hidden::make('requires_timetable_revision')->default(false),
                    Select::make('action')->label('Decision')->options(function (Get $get): array {
                        if ($get('section_id')) {
                            if ($get('requires_timetable_revision')) {
                                return ['keep' => 'Keep existing class'];
                            }

                            return $get('is_confirmed')
                                ? ['keep' => 'Keep confirmed class', 'cancel' => 'Cancel existing class']
                                : ['confirm' => 'Prepare and confirm', 'keep' => 'Keep as Draft', 'cancel' => 'Cancel existing class'];
                        }

                        return ['confirm' => 'Prepare class', 'skip' => 'Do not add a class'];
                    })->helperText(fn (Get $get): ?string => $get('requires_timetable_revision')
                        ? 'This class has a published timetable. Keep it here; changes require the controlled timetable revision.'
                        : null)->default('skip')->required()->live(),
                    Fieldset::make('Prepare this course')->columns(2)->columnSpanFull()->visible(fn (Get $get): bool => $get('action') === 'confirm')->schema([
                        TextInput::make('code')->label('Class reference')->maxLength(64)->required(fn (Get $get): bool => $get('action') === 'confirm')->disabled(fn (Get $get): bool => $get('action') !== 'confirm')->dehydrated(),
                        TextInput::make('capacity')->label('Approved capacity')->integer()->minValue(1)->required(fn (Get $get): bool => $get('action') === 'confirm')->disabled(fn (Get $get): bool => $get('action') !== 'confirm' || filled($get('section_id')))->dehydrated(),
                        Select::make('source')->options(Section::confirmableSourceOptions())->required()->live()->disabled(fn (Get $get): bool => $get('action') !== 'confirm')->dehydrated(),
                        Select::make('modality')->label('Delivery')->options(TermOffering::modalityOptions())->required(fn (Get $get): bool => $get('action') === 'confirm')->disabled(fn (Get $get): bool => $get('action') !== 'confirm')->dehydrated(),
                        TextInput::make('authority_reference')->label('Additional-class approval reference')->maxLength(255)->required(fn (Get $get): bool => $get('action') === 'confirm' && $get('source') === Section::SourceAdditional)->visible(fn (Get $get): bool => $get('source') === Section::SourceAdditional),
                        Repeater::make('cohorts')->label('Student sections served')->defaultItems(0)->minItems(1)->required(fn (Get $get): bool => $get('action') === 'confirm')->reorderable(false)->addActionLabel('Link a student section')->schema([
                            Select::make('id')->label('Student section')->options($cohorts)->searchable()->required()->distinct(),
                            TextInput::make('count')->label('Learners in this class')->integer()->minValue(1)->required(),
                        ])->columns(2)->columnSpanFull(),
                    ]),
                    Textarea::make('cancellation_reason')->label('Reason for cancellation')->maxLength(2000)->required()->visible(fn (Get $get): bool => $get('action') === 'cancel')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public function addCoursesAction(): Action
    {
        return Action::make('addCourses')->label('Add courses')->icon('heroicon-o-plus')
            ->modalHeading('Add courses to this proposal')
            ->modalDescription('Choose the courses that will run. Program and year identify their curriculum source. You can select a course again for another split class.')
            ->modalSubmitActionLabel('Add selected courses')
            ->schema([
                Select::make('program')->label('Program')->searchable()->required()->live()->dehydrated(false)
                    ->options(fn (): array => collect($this->currentPreview()['catalog'])->pluck('program', 'program')->sort()->all())
                    ->afterStateUpdated(fn (Set $set) => $set('entries', [])),
                Select::make('entries')->label('Courses')->multiple()->searchable()->required()
                    ->disabled(fn (Get $get): bool => blank($get('program')))
                    ->helperText('Courses are grouped by curriculum year. Other-Term courses need additional-class approval.')
                    ->options(fn (Get $get): array => collect($this->currentPreview()['catalog'])->where('program', $get('program'))
                        ->groupBy(fn (array $course): string => $course['year_level'])
                        ->map(fn ($courses): array => $courses->mapWithKeys(fn (array $course): array => [$course['id'] => $course['course_title'].' · '.$course['course_code'].($course['term_type'] !== $this->selectedTerm()->type ? ' · Additional approval required' : '')])->all())->all()),
            ])
            ->action(function (array $data): void {
                $catalog = collect($this->currentPreview()['catalog'])->keyBy('id');
                $state = $this->form->getRawState();
                foreach (array_unique($data['entries']) as $id) {
                    $course = $catalog->get((int) $id);
                    if (! $course) {
                        throw ValidationException::withMessages(['entries' => 'Choose a course from the approved catalog.']);
                    }
                    $state['classes'][] = [
                        'entry_id' => $course['id'], 'section_id' => null, 'is_catalog' => true, 'is_confirmed' => false,
                        'action' => 'confirm', 'code' => '', 'capacity' => null,
                        'source' => $course['term_type'] === $this->selectedTerm()->type ? Section::SourceRegular : Section::SourceAdditional,
                        'modality' => count($course['modes']) === 1 ? $course['modes'][0] : null,
                        'authority_reference' => null, 'cohorts' => [],
                    ];
                }
                $this->form->fill($state);
            });
    }

    public function confirmProposalAction(): Action
    {
        return Action::make('confirmProposal')->label('Review class proposal')->requiresConfirmation()

            ->disabled(fn (): bool => ! $this->hasProposalChanges())
            ->modalHeading('Confirm this Term’s class proposal')
            ->modalDescription('Prepared classes and cancellations are checked and saved together. Skipped courses and retained classes stay unchanged. Faculty and room readiness are checked before generation.')
            ->modalSubmitActionLabel('Confirm proposal')
            ->action(function (): void {
                $state = $this->form->getState();
                $result = app(PrepareTermClassProposal::class)->confirm($this->selectedTerm(), auth()->user(), array_values($state['classes'] ?? []), $this->proposalSource);
                Notification::make()->title($result['confirmed'].' '.Str::plural('class', $result['confirmed']).' confirmed · '.$result['cancelled'].' '.Str::plural('class', $result['cancelled']).' cancelled')->success()->send();
                $this->redirect(TermClasses::getUrl(['termId' => $this->termId]));
            });
    }

    public function hasProposalChanges(): bool
    {
        return collect($this->proposal['classes'] ?? [])->contains(fn (array $row): bool => in_array($row['action'] ?? null, ['confirm', 'cancel'], true));
    }

    public function viewApprovedCurriculumAction(): Action
    {
        return Action::make('viewApprovedCurriculum')
            ->label('View approved curriculum')
            ->color('gray')
            ->modalHeading('Approved curriculum reference')
            ->modalWidth('7xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->unsavedChangesAlert(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (): View => view('filament.pages.partials.term-approved-curriculum', [
                'catalog' => $this->currentPreview()['catalog'],
                'termType' => $this->selectedTerm()->type,
            ]));
    }

    protected function getViewData(): array
    {
        $preview = $this->currentPreview();
        $matchingCurriculumCount = collect($preview['catalog'])->where('term_type', $this->selectedTerm()->type)->count();

        return [...parent::getViewData(), 'approvedCurriculumCount' => count($preview['catalog']),
            'matchingCurriculumCount' => $matchingCurriculumCount, 'demandEvidence' => $preview['demand_evidence']];
    }
}
