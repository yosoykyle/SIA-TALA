<?php

namespace App\Support;

use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\Scheduling\RecordTermCohort;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\AcademicYear;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Room;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait HasClassPreparationActions
{
    public function recordCohortAction(): Action
    {
        return Action::make('recordCohort')
            ->label('Add student section')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->modalHeading('Add student section')
            ->modalSubmitActionLabel('Add student section')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => $this->termId !== null
                && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed())
            ->modalDescription(fn (): string => $this->selectedTerm()->label.'. Choose the program and year level to resolve the cohort batch and curriculum.')
            ->schema(function (): array {
                $term = $this->selectedTerm();
                $term->loadMissing('academicYear');
                $activePackage = TermCalendarPackage::query()
                    ->where('term_id', $term->id)
                    ->where('state', TermCalendarPackage::StateActive)
                    ->first();
                $maxRoomSeats = (int) (Room::query()->where('is_active', true)->max('capacity') ?? 0);
                $curricula = CurriculumVersion::query()
                    ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                    ->with(['program', 'batches.academicYear', 'academicYears'])
                    ->get()
                    ->groupBy('program_id')
                    ->sortBy(fn (Collection $group): string => (string) ($group->first()?->program?->code ?? ''))
                    ->flatMap(fn (Collection $group): Collection => CurriculumVersion::sortByLatestBatch($group, groupByState: true))
                    ->keyBy('id');
                $programs = $curricula
                    ->map(fn (CurriculumVersion $curriculum): ?Program => $curriculum->program)
                    ->filter(fn (?Program $program): bool => $program instanceof Program)
                    ->unique('id')
                    ->sortBy(fn (Program $program): string => strtoupper((string) ($program->name ?: $program->code)))
                    ->keyBy('id');

                return [
                    Hidden::make('suggested_reference')->dehydrated(false),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('program_id')
                            ->label('Program')
                            ->placeholder('Choose a program')
                            ->options(fn (): array => $programs
                                ->mapWithKeys(fn (Program $program): array => [
                                    $program->id => collect([$program->code, $program->name])->filter()->implode(' — '),
                                ])->all())
                            ->dehydrated(false)
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($term, $activePackage, $curricula, $programs): void {
                                $program = $programs->get((int) $state);
                                if (! $program instanceof Program) {
                                    $set('curriculum_version_id', null);
                                    $set('suggested_reference', '');
                                    $set('auto_create_classes', false);

                                    return;
                                }

                                $maxYear = $this->maxYearLevelForProgram($program);
                                $currentYear = (int) ($get('year_level') ?? 1);
                                $resolvedYear = ($currentYear >= 1 && $currentYear <= $maxYear) ? $currentYear : 1;
                                $matchedCurriculum = $this->resolveExpectedCurriculumForYearLevel($term, $program, $resolvedYear)
                                    ?? $curricula->first(fn (CurriculumVersion $curriculum): bool => (int) $curriculum->program_id === (int) $program->id);

                                $set('year_level', $resolvedYear);
                                $set('curriculum_version_id', $matchedCurriculum?->id);
                                $this->syncSuggestedSectionReference($set, $get, $term, $matchedCurriculum, $resolvedYear);

                                $hasMatching = $activePackage !== null
                                    && $matchedCurriculum instanceof CurriculumVersion
                                    && $this->confirmableEntriesForCohort($term, $matchedCurriculum->id, $resolvedYear)->isNotEmpty();
                                $set('auto_create_classes', $hasMatching);
                            }),
                        Select::make('year_level')
                            ->label('Year level')
                            ->options(function (Get $get) use ($curricula, $programs): array {
                                $program = $programs->get((int) ($get('program_id') ?? 0))
                                    ?? $curricula->get((int) ($get('curriculum_version_id') ?? 0))?->program;

                                return $this->cohortYearLevelOptionsForProgram($program);
                            })
                            ->default(1)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($term, $activePackage, $curricula, $programs): void {
                                $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                                $curriculum = $curricula->get($curriculumId);
                                $program = $programs->get((int) ($get('program_id') ?? 0)) ?? $curriculum?->program;
                                $yearLevel = max(1, (int) ($state ?? 1));

                                // Auto-match the program's published curriculum for expected intake batch: termStartYear - (yearLevel - 1).
                                $matchedCurriculum = $this->resolveExpectedCurriculumForYearLevel($term, $program, $yearLevel);
                                if ($matchedCurriculum instanceof CurriculumVersion && $curricula->has($matchedCurriculum->id) && (int) $matchedCurriculum->id !== $curriculumId) {
                                    $curriculumId = (int) $matchedCurriculum->id;
                                    $curriculum = $curricula->get($curriculumId);
                                    $set('curriculum_version_id', $curriculumId);
                                }

                                if ($curriculum instanceof CurriculumVersion && blank($get('program_id'))) {
                                    $set('program_id', (int) $curriculum->program_id);
                                }

                                $this->syncSuggestedSectionReference($set, $get, $term, $curriculum, $yearLevel);

                                $hasMatching = $activePackage !== null
                                    && $curriculumId > 0
                                    && $yearLevel > 0
                                    && $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel)->isNotEmpty();
                                $set('auto_create_classes', $hasMatching);
                            }),
                    ]),
                    Select::make('curriculum_version_id')
                        ->label('Curriculum')
                        ->placeholder('Choose a curriculum')
                        ->options(function (Get $get) use ($curricula): array {
                            $selectedProgramId = (int) ($get('program_id') ?? 0);
                            $scoped = $selectedProgramId > 0
                                ? $curricula->filter(fn (CurriculumVersion $curriculum): bool => (int) $curriculum->program_id === $selectedProgramId)
                                : $curricula;

                            return $scoped
                                ->mapWithKeys(fn (CurriculumVersion $curriculum): array => [
                                    $curriculum->id => $this->formatCurriculumOptionLabel($curriculum, includeProgram: $selectedProgramId <= 0),
                                ])->all();
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($term, $activePackage, $curricula): void {
                            $curriculum = $curricula->get((int) $state);
                            if ($curriculum instanceof CurriculumVersion && (int) ($get('program_id') ?? 0) !== (int) $curriculum->program_id) {
                                $set('program_id', (int) $curriculum->program_id);
                            }

                            $maxYear = $this->maxYearLevelForCurriculum($curriculum);
                            $currentYear = (int) ($get('year_level') ?? 1);
                            $resolvedYear = ($currentYear >= 1 && $currentYear <= $maxYear) ? $currentYear : 1;
                            $set('year_level', $resolvedYear);
                            $this->syncSuggestedSectionReference($set, $get, $term, $curriculum, $resolvedYear);

                            $hasMatching = $activePackage !== null
                                && $curriculum instanceof CurriculumVersion
                                && $this->confirmableEntriesForCohort($term, $curriculum->id, $resolvedYear)->isNotEmpty();
                            $set('auto_create_classes', $hasMatching);
                        })
                        ->helperText(function (Get $get) use ($term, $curricula): string {
                            $curriculum = $curricula->get((int) ($get('curriculum_version_id') ?? 0));
                            $yearLevel = max(1, (int) ($get('year_level') ?? 1));
                            $intakeLabel = $this->resolveSingleIntakeBatchLabel($term, $yearLevel);

                            if (! $curriculum instanceof CurriculumVersion) {
                                return $intakeLabel !== null
                                    ? "Select a program and year level above to auto-select the curriculum for {$intakeLabel}."
                                    : 'Auto-selected from program and year level; change here if this section follows another published curriculum.';
                            }

                            $resolvedLabel = $this->resolvedBatchDisplay($term, $curriculum, $yearLevel);
                            $programCount = $curricula->where('program_id', $curriculum->program_id)->count();
                            $overrideHint = $programCount > 1 ? ' Change here only if overriding the curriculum.' : '';

                            return "Resolved batch: {$resolvedLabel} (Year {$yearLevel}).{$overrideHint}";
                        }),
                    Placeholder::make('resolved_batch_summary')
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => filled($get('curriculum_version_id')))
                        ->content(function (Get $get) use ($term, $curricula): HtmlString {
                            $curriculum = $curricula->get((int) ($get('curriculum_version_id') ?? 0));
                            if (! $curriculum instanceof CurriculumVersion) {
                                return new HtmlString('');
                            }

                            $yearLevel = max(1, (int) ($get('year_level') ?? 1));
                            $batchDisplay = $this->resolvedBatchDisplay($term, $curriculum, $yearLevel) ?? $curriculum->batchLabel();
                            $programLabel = $curriculum->program?->code ?: $curriculum->program?->name;
                            $curriculumTitle = filled($curriculum->name) && $curriculum->name !== $curriculum->batchLabel()
                                ? $curriculum->name
                                : $curriculum->batchLabel();

                            return new HtmlString(
                                '<div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">'
                                .'<strong>'.e($batchDisplay).'</strong> · '.e($programLabel).' · Year '.e((string) $yearLevel).' · '.e($curriculumTitle)
                                .'</div>'
                            );
                        }),
                    TextInput::make('reference')
                        ->label('Section name')
                        ->placeholder('e.g. BSIT 1-1')
                        ->live(debounce: 250)
                        ->helperText(function (Get $get) use ($curricula): string {
                            $yearLevel = max(1, (int) ($get('year_level') ?? 1));
                            $curriculum = $curricula->get((int) ($get('curriculum_version_id') ?? 0));
                            $programCode = Str::upper(trim((string) ($curriculum?->program?->code ?? 'BSIT'))) ?: 'BSIT';

                            return "Example: {$programCode} {$yearLevel}-1 (Year {$yearLevel}, Section 1)";
                        })
                        ->required()
                        ->maxLength(64),
                    Placeholder::make('batch_year_mismatch_warning')
                        ->hiddenLabel()
                        ->visible(function (Get $get) use ($term, $curricula): bool {
                            $curriculum = $curricula->get((int) ($get('curriculum_version_id') ?? 0));
                            $yearLevel = (int) ($get('year_level') ?? 1);

                            return $this->batchYearMismatchAdvisory($term, $curriculum, $yearLevel) !== null;
                        })
                        ->content(function (Get $get) use ($term, $curricula): HtmlString {
                            $curriculum = $curricula->get((int) ($get('curriculum_version_id') ?? 0));
                            $yearLevel = (int) ($get('year_level') ?? 1);
                            $advisory = $this->batchYearMismatchAdvisory($term, $curriculum, $yearLevel);

                            if ($advisory === null) {
                                return new HtmlString('');
                            }

                            return new HtmlString(
                                '<div class="tala-inline-prereq">'
                                .'<div class="tala-inline-prereq__body">'
                                .'<p class="tala-inline-prereq__title">'.e($advisory['title']).'</p>'
                                .'<p class="tala-inline-prereq__text">'.e($advisory['body']).'</p>'
                                .'</div>'
                                .'</div>'
                            );
                        }),
                    Placeholder::make('curriculum_gap_warning')
                        ->hiddenLabel()
                        ->visible(function (Get $get) use ($term): bool {
                            $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                            $yearLevel = (int) ($get('year_level') ?? 1);
                            if ($curriculumId <= 0 || $yearLevel <= 0) {
                                return false;
                            }

                            return $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel)->isEmpty();
                        })
                        ->content(function (Get $get) use ($term, $curricula): HtmlString {
                            $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                            $yearLevel = max(1, (int) ($get('year_level') ?? 1));
                            $curriculum = $curricula->get($curriculumId);
                            $programCode = e($curriculum?->program?->code ?: ($curriculum?->program?->name ?? 'Program'));
                            $batchLabel = e($curriculum?->batchLabel() ?? 'Selected curriculum');
                            $termTypeLabel = e($term->typeLabel());
                            $periodsByCurriculum = $this->configuredSubjectPeriodsByCurriculum([$curriculumId]);
                            $configuredSummary = $this->formatConfiguredPeriods(
                                $periodsByCurriculum[$curriculumId] ?? [],
                                style: 'paren',
                                skipTermType: $term->type,
                                skipYearLevel: $yearLevel,
                            );
                            $url = e(CurriculumVersionResource::getUrl('review', ['record' => $curriculumId]));

                            $detail = $configuredSummary !== null
                                ? 'Subjects are currently configured for: '.e($configuredSummary).'.'
                                : 'No subjects are configured in this curriculum yet.';

                            return new HtmlString(
                                '<div class="tala-inline-prereq">'
                                .'<div class="tala-inline-prereq__body">'
                                ."<p class=\"tala-inline-prereq__title\">{$programCode} · {$batchLabel} (Year {$yearLevel}) has 0 subjects configured for {$termTypeLabel}.</p>"
                                ."<p class=\"tala-inline-prereq__text\">{$detail} You can still save this student section now, or <a href=\"{$url}\" class=\"fi-link font-medium\">Open curriculum</a>.</p>"
                                .'</div>'
                                .'</div>'
                            );
                        }),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('confirmed_count')
                            ->label('Expected students')
                            ->placeholder('e.g., 35')
                            ->helperText(fn (Get $get): string => $this->scopedExpectedStudentsHelperText(
                                $term,
                                $maxRoomSeats,
                                $curricula->get((int) ($get('curriculum_version_id') ?? 0)),
                                filled($get('program_id')) ? (int) $get('program_id') : null,
                                (int) ($get('year_level') ?? 1),
                            ))
                            ->required()
                            ->integer()
                            ->minValue(1),
                        TextInput::make('section_count')
                            ->label('How many sections')
                            ->default(1)
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->live(debounce: 200)
                            ->helperText(function (Get $get) use ($curricula): string {
                                $count = max(1, min(10, (int) ($get('section_count') ?? 1)));
                                $yearLevel = max(1, (int) ($get('year_level') ?? 1));
                                $curriculum = $curricula->get((int) ($get('curriculum_version_id') ?? 0));
                                $programCode = Str::upper(trim((string) ($curriculum?->program?->code ?? 'BSIT'))) ?: 'BSIT';
                                $baseRef = trim((string) ($get('reference') ?? ''));

                                if ($count > 1) {
                                    $preview = $this->deriveSectionReferences(
                                        $baseRef !== '' ? $baseRef : $programCode,
                                        $count,
                                        $yearLevel,
                                    );

                                    return 'Will create: '.implode(', ', $preview);
                                }

                                return "Leave as 1, or enter 2 to 10 to create consecutive blocks (example: {$programCode} {$yearLevel}-1, {$programCode} {$yearLevel}-2).";
                            }),
                    ]),
                    FormSection::make()
                        ->compact()
                        ->visible($activePackage !== null)
                        ->extraAttributes(fn (Get $get): array => [
                            'class' => 'tala-auto-classes-card'.((bool) ($get('auto_create_classes') ?? false) ? ' is-enabled' : ''),
                        ])
                        ->schema([
                            Toggle::make('auto_create_classes')
                                ->label('Auto-load & confirm curriculum classes now')
                                ->onColor('primary')
                                ->live()
                                ->default($activePackage !== null)
                                ->dehydrated()
                                ->disabled(function (Get $get) use ($term): bool {
                                    $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                                    $yearLevel = (int) ($get('year_level') ?? 1);
                                    if ($curriculumId <= 0 || $yearLevel <= 0) {
                                        return false;
                                    }

                                    return $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel)->isEmpty();
                                }),
                            Placeholder::make('auto_create_classes_preview')
                                ->hiddenLabel()
                                ->content(function (Get $get) use ($term): HtmlString {
                                    $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                                    $yearLevel = max(1, (int) ($get('year_level') ?? 1));
                                    $sectionCount = max(1, min(10, (int) ($get('section_count') ?? 1)));
                                    $isEnabled = (bool) ($get('auto_create_classes') ?? false);
                                    $termTypeLabel = $term->typeLabel();

                                    if ($curriculumId <= 0) {
                                        return new HtmlString('<p class="tala-auto-classes-card__copy">Select a program curriculum above to preview which classes will be created automatically.</p>');
                                    }

                                    $entries = $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel);
                                    $subjectCount = $entries->count();

                                    if ($subjectCount === 0) {
                                        return new HtmlString(
                                            '<p class="tala-auto-classes-card__copy">No '.e($termTypeLabel).' Year '.$yearLevel.' subjects are set up in this curriculum yet, so only the student '.Str::plural('section', $sectionCount).' will be created.</p>'
                                        );
                                    }

                                    if (! $isEnabled) {
                                        return new HtmlString(
                                            '<p class="tala-auto-classes-card__copy">Only the student '.Str::plural('section', $sectionCount).' will be created. You can load and confirm the '.$subjectCount.' matching curriculum '.Str::plural('subject', $subjectCount).' manually later from the table.</p>'
                                        );
                                    }

                                    $totalClasses = $subjectCount * $sectionCount;
                                    $subjectPills = $entries->take(4)->map(function (CurriculumEntry $entry): string {
                                        $code = trim((string) ($entry->courseSpecification?->course?->code ?? ''));
                                        $title = trim((string) ($entry->courseSpecification?->title ?? ''));
                                        $label = $code !== '' && $title !== '' ? "{$code} · {$title}" : ($code !== '' ? $code : $title);

                                        return '<span class="tala-auto-classes-card__pill">'.e($label).'</span>';
                                    })->implode('');

                                    $extraCount = max(0, $subjectCount - 4);
                                    if ($extraCount > 0) {
                                        $subjectPills .= '<span class="tala-auto-classes-card__pill is-more">+'.$extraCount.' more</span>';
                                    }

                                    $breakdown = $sectionCount > 1
                                        ? " ({$subjectCount} ".Str::plural('subject', $subjectCount)." × {$sectionCount} sections)"
                                        : '';

                                    return new HtmlString(
                                        '<div class="tala-auto-classes-card__body">'
                                        .'<div class="tala-auto-classes-card__summary">'
                                        .'<span class="tala-auto-classes-card__badge">Recommended</span>'
                                        .'<span class="tala-auto-classes-card__summary-text">Creates <strong>'.$totalClasses.' confirmed '.Str::plural('class', $totalClasses).'</strong>'.e($breakdown).' ready for Step 3, skipping manual subject loading.</span>'
                                        .'</div>'
                                        .'<div class="tala-auto-classes-card__pills">'.$subjectPills.'</div>'
                                        .'</div>'
                                    );
                                }),
                        ]),
                    Placeholder::make('auto_create_classes_notice')
                        ->hiddenLabel()
                        ->content('Activate the term calendar in 1. Calendar to auto-create classes from curriculum.')
                        ->visible($activePackage === null),
                ];
            })
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $term = $this->selectedTerm();
                if ($this->hasPublishedTimetable($term)) {
                    Notification::make()
                        ->title('Cannot add student section')
                        ->body('The official timetable is already published. Use Timetable → Revise official schedule for changes.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages([$schema->getStatePath().'.reference' => 'The official timetable is already published.']);
                }

                $sectionCount = max(1, min(10, (int) ($data['section_count'] ?? 1)));
                $resolvedYearLevel = filled($data['year_level'] ?? null)
                    ? (int) $data['year_level']
                    : (TermCohort::parseYearLevelValue($data['reference'] ?? null) ?? 1);
                $references = $this->deriveSectionReferences((string) ($data['reference'] ?? ''), $sectionCount, $resolvedYearLevel);
                $createdClassesCount = 0;

                try {
                    DB::transaction(function () use ($term, $actor, $data, $references, $resolvedYearLevel, &$createdClassesCount): void {
                        $createdCohorts = [];
                        foreach ($references as $reference) {
                            $createdCohorts[] = app(RecordTermCohort::class)->execute($term, $actor, [
                                ...$data,
                                'year_level' => $resolvedYearLevel,
                                'reference' => $reference,
                            ]);
                        }

                        if (! (bool) ($data['auto_create_classes'] ?? false) || ! $actor->can('create', TermOffering::class)) {
                            return;
                        }

                        $hasActiveCalendar = TermCalendarPackage::query()
                            ->where('term_id', $term->id)
                            ->where('state', TermCalendarPackage::StateActive)
                            ->exists();

                        if (! $hasActiveCalendar) {
                            return;
                        }

                        $entries = $this->confirmableEntriesForCohort($term, (int) $data['curriculum_version_id'], $resolvedYearLevel);
                        if ($entries->isEmpty()) {
                            return;
                        }

                        $proposal = app(PrepareTermClassProposal::class);
                        $preview = $proposal->preview($term);
                        $rows = [];

                        foreach ($createdCohorts as $cohort) {
                            foreach ($entries as $entry) {
                                $modes = $entry->courseSpecification?->allowed_modalities ?? [];
                                $rows[] = [
                                    'entry_id' => $entry->id,
                                    'section_id' => null,
                                    'action' => 'confirm',
                                    'code' => '',
                                    'capacity' => max((int) $cohort->confirmed_count, 1),
                                    'source' => Section::SourceRegular,
                                    'modality' => $modes[0] ?? TermOffering::ModalityFaceToFace,
                                    'authority_reference' => null,
                                    'cohorts' => [
                                        ['id' => (int) $cohort->id, 'count' => (int) $cohort->confirmed_count],
                                    ],
                                ];
                            }
                        }

                        if ($rows !== []) {
                            $result = $proposal->confirm($term, $actor, $rows, $preview['token']);
                            $createdClassesCount = (int) ($result['confirmed'] ?? 0);
                        }
                    }, attempts: 5);
                } catch (ValidationException $exception) {
                    if (isset($exception->errors()['term_id'])) {
                        Notification::make()->title('Cannot add student section')->body($exception->errors()['term_id'][0])->danger()->persistent()->send();
                    }
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all());
                }

                $maxRoomSeats = (int) (Room::query()->where('is_active', true)->max('capacity') ?? 0);
                $expected = (int) ($data['confirmed_count'] ?? 0);
                $summaryParts = [];
                if ($sectionCount > 1) {
                    $summaryParts[] = 'Created: '.implode(', ', $references);
                }
                if ($createdClassesCount > 0) {
                    $summaryParts[] = $createdClassesCount.' '.Str::plural('class', $createdClassesCount).' automatically created and confirmed';
                }

                if ($maxRoomSeats > 0 && $expected > $maxRoomSeats) {
                    Notification::make()
                        ->title($sectionCount > 1 ? "{$sectionCount} student sections recorded (exceeds room seat cap)" : 'Student section recorded (exceeds room seat cap)')
                        ->body("Expected students ({$expected}) exceeds the largest active room ({$maxRoomSeats} seats). Consider splitting sections or using online delivery.")
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($sectionCount > 1 ? "{$sectionCount} student sections recorded" : 'Student section recorded')
                    ->body($summaryParts !== [] ? implode(' · ', $summaryParts).'.' : null)
                    ->success()
                    ->send();
            });
    }

    public function resolveExpectedCurriculumForYearLevel(Term $term, ?Program $program, int $yearLevel): ?CurriculumVersion
    {
        if (! $program instanceof Program || $yearLevel <= 0) {
            return null;
        }

        $term->loadMissing('academicYear');
        if (! $term->academicYear instanceof AcademicYear) {
            return null;
        }

        $termStartYear = CurriculumVersion::extractAcademicYearStart($term->academicYear);
        if ($termStartYear === null) {
            return null;
        }

        $expectedIntakeYear = $termStartYear - ($yearLevel - 1);

        return $program->curriculumForIntakeStartYear($expectedIntakeYear);
    }

    /**
     * @return array{title: string, body: string}|null
     */
    public function batchYearMismatchAdvisory(Term $term, ?CurriculumVersion $curriculum, int $yearLevel): ?array
    {
        if (! $curriculum instanceof CurriculumVersion || $yearLevel <= 0) {
            return null;
        }

        $term->loadMissing('academicYear');
        if (! $term->academicYear instanceof AcademicYear) {
            return null;
        }

        $termStartYear = CurriculumVersion::extractAcademicYearStart($term->academicYear);
        if ($termStartYear === null) {
            return null;
        }

        $expectedIntakeYear = $termStartYear - ($yearLevel - 1);
        $curriculum->loadMissing(['program', 'academicYears']);

        if ($curriculum->academicYears->isEmpty() || $curriculum->coversAcademicYearStart($expectedIntakeYear)) {
            return null;
        }

        $expectedShort = $expectedIntakeYear.'–'.substr((string) ($expectedIntakeYear + 1), -2);
        $expectedCurriculum = $curriculum->program instanceof Program
            ? $curriculum->program->curriculumForIntakeStartYear($expectedIntakeYear)
            : null;
        $programCode = (string) ($curriculum->program?->code ?? 'Program');

        if ($expectedCurriculum instanceof CurriculumVersion && (int) $expectedCurriculum->id !== (int) $curriculum->id) {
            return [
                'title' => "Batch mismatch: Year {$yearLevel} in {$term->academicYear->label} normally uses Batch {$expectedShort}",
                'body' => "You selected {$programCode} · {$curriculum->batchLabel()}, while Batch {$expectedShort} is assigned to {$expectedCurriculum->batchLabel()}. Confirm this only if creating an intentional off-batch section.",
            ];
        }

        return [
            'title' => "Batch check: Year {$yearLevel} in {$term->academicYear->label} corresponds to Batch {$expectedShort}",
            'body' => "{$programCode} · {$curriculum->batchLabel()} does not include Batch {$expectedShort}. Assign Batch {$expectedShort} to a curriculum under Programs & Curricula if this continuing cohort follows a different plan.",
        ];
    }

    /**
     * Compute suggested TermCohort rollover rows across active programs using dynamic `program.duration_years - 1`.
     *
     * @return list<array{
     *     key: string,
     *     program_id: int,
     *     program_code: string,
     *     program_name: string,
     *     year_level: int,
     *     intake_year: int,
     *     batch_short: string,
     *     curriculum_version_id: int,
     *     curriculum_label: string,
     *     is_retired: bool,
     *     is_older_batch: bool,
     *     reference: string,
     *     suggested_count: int,
     *     active_students: int,
     *     label: string,
     *     description: string
     * }>
     */
    public function suggestedSectionsForTerm(Term $term, bool $includeOlderBatches = false): array
    {
        $term->loadMissing('academicYear');
        $termStartYear = $term->academicYear instanceof AcademicYear
            ? CurriculumVersion::extractAcademicYearStart($term->academicYear)
            : null;

        if ($termStartYear === null) {
            return [];
        }

        $existingCohorts = TermCohort::query()
            ->where('term_id', $term->id)
            ->get(['program_id', 'year_level', 'curriculum_version_id', 'reference']);

        $existingProgramYears = [];
        foreach ($existingCohorts as $cohort) {
            $year = $cohort->resolvedYearLevel();
            if ($year !== null) {
                $existingProgramYears[((int) $cohort->program_id).':'.$year] = true;
            }
        }

        $activePrograms = Program::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $matchingAyIdsByYear = [];
        $suggestions = [];

        foreach ($activePrograms as $program) {
            $maxYear = max(1, min(8, (int) ($program->duration_years ?: 3)));
            $yearLevels = range(1, $maxYear);

            foreach ($yearLevels as $yearLevel) {
                if (isset($existingProgramYears[$program->id.':'.$yearLevel])) {
                    continue;
                }

                $intakeYear = $termStartYear - ($yearLevel - 1);
                $curriculum = $program->curriculumForIntakeStartYear($intakeYear);

                if (! $curriculum instanceof CurriculumVersion) {
                    continue;
                }

                $curriculum->loadMissing('academicYears');
                $matchingAyIds = $matchingAyIdsByYear[$intakeYear]
                    ??= PrepareTermClassProposal::matchingAcademicYearIdsForStartYear($intakeYear);
                $earliestBatchStart = $curriculum->academicYears
                    ->map(fn (AcademicYear $year): ?int => CurriculumVersion::extractAcademicYearStart($year))
                    ->filter(fn (?int $start): bool => $start !== null)
                    ->min();
                $isPrimaryBatch = $curriculum->academicYears->count() <= 1 || $earliestBatchStart === $intakeYear;

                $batchShort = $intakeYear.'–'.substr((string) ($intakeYear + 1), -2);
                $activeStudentsQuery = StudentProfile::query()
                    ->where('program_id', $program->id)
                    ->where('curriculum_version_id', $curriculum->id)
                    ->where('lifecycle_status', StudentProfile::LifecycleActive)
                    ->whereNull('archived_at')
                    ->whereNull('merged_into_id');
                PrepareTermClassProposal::applyIntakeBatchScope($activeStudentsQuery, $matchingAyIds, $isPrimaryBatch);
                $activeStudents = $activeStudentsQuery->count();

                $reference = $this->suggestSectionReference($term, $curriculum, $yearLevel);
                $suggestedCount = $activeStudents > 0 ? $activeStudents : 30;
                $retiredTag = $curriculum->isRetired() ? ' (Retired)' : '';
                $key = "{$program->id}:{$yearLevel}:{$curriculum->id}";

                $suggestions[] = [
                    'key' => $key,
                    'program_id' => (int) $program->id,
                    'program_code' => (string) $program->code,
                    'program_name' => (string) $program->name,
                    'year_level' => $yearLevel,
                    'intake_year' => $intakeYear,
                    'batch_short' => $batchShort,
                    'curriculum_version_id' => (int) $curriculum->id,
                    'curriculum_label' => $curriculum->batchLabel(),
                    'is_retired' => $curriculum->isRetired(),
                    'is_older_batch' => false,
                    'reference' => $reference,
                    'suggested_count' => $suggestedCount,
                    'active_students' => $activeStudents,
                    'label' => "{$reference} · {$program->code} Year {$yearLevel} (Batch {$batchShort} → {$curriculum->batchLabel()}{$retiredTag})",
                    'description' => $activeStudents > 0
                        ? "{$activeStudents} active continuing ".Str::plural('student', $activeStudents)." in Batch {$batchShort} · Suggested capacity: {$suggestedCount}"
                        : "Planned section for Batch {$batchShort} ({$program->duration_years}-yr program) · Default expected count: {$suggestedCount}",
                ];
            }

            if ($includeOlderBatches) {
                $oldestRegularIntake = $termStartYear - $program->intakeBatchLookbackYears();
                $olderCurricula = CurriculumVersion::query()
                    ->where('program_id', $program->id)
                    ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                    ->with(['program', 'academicYears'])
                    ->get()
                    ->filter(function (CurriculumVersion $cv) use ($oldestRegularIntake): bool {
                        $latestStart = $cv->latestBatchStartYear();

                        return $latestStart !== null && $latestStart < $oldestRegularIntake;
                    });

                foreach ($olderCurricula as $olderCurriculum) {
                    $olderIntake = (int) $olderCurriculum->latestBatchStartYear();
                    $olderShort = $olderIntake.'–'.substr((string) ($olderIntake + 1), -2);
                    $key = "{$program->id}:{$maxYear}:{$olderCurriculum->id}";
                    if (collect($suggestions)->contains('key', $key)) {
                        continue;
                    }

                    $olderMatchingAyIds = $matchingAyIdsByYear[$olderIntake]
                        ??= PrepareTermClassProposal::matchingAcademicYearIdsForStartYear($olderIntake);
                    $olderIsSingleBatch = $olderCurriculum->academicYears->count() <= 1;
                    $activeStudentsQuery = StudentProfile::query()
                        ->where('program_id', $program->id)
                        ->where('curriculum_version_id', $olderCurriculum->id)
                        ->where('lifecycle_status', StudentProfile::LifecycleActive)
                        ->whereNull('archived_at')
                        ->whereNull('merged_into_id');
                    PrepareTermClassProposal::applyIntakeBatchScope($activeStudentsQuery, $olderMatchingAyIds, $olderIsSingleBatch);
                    $activeStudents = $activeStudentsQuery->count();

                    $reference = "{$program->code} {$maxYear}-OB{$olderIntake}";
                    $suggestedCount = $activeStudents > 0 ? $activeStudents : 15;

                    $suggestions[] = [
                        'key' => $key,
                        'program_id' => (int) $program->id,
                        'program_code' => (string) $program->code,
                        'program_name' => (string) $program->name,
                        'year_level' => $maxYear,
                        'intake_year' => $olderIntake,
                        'batch_short' => $olderShort,
                        'curriculum_version_id' => (int) $olderCurriculum->id,
                        'curriculum_label' => $olderCurriculum->batchLabel(),
                        'is_retired' => $olderCurriculum->isRetired(),
                        'is_older_batch' => true,
                        'reference' => $reference,
                        'suggested_count' => $suggestedCount,
                        'active_students' => $activeStudents,
                        'label' => "{$reference} · {$program->code} Older Batch ({$olderCurriculum->batchLabel()})",
                        'description' => $activeStudents > 0
                            ? "{$activeStudents} active returning/continuing ".Str::plural('student', $activeStudents).' · Older batch override'
                            : "Older batch ({$olderShort}) for LOA/returnee continuing students · Suggested count: {$suggestedCount}",
                    ];
                }
            }
        }

        return $suggestions;
    }

    public function suggestSectionsForTermAction(): Action
    {
        return Action::make('suggestSectionsForTerm')
            ->label('Suggest sections')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalHeading('Suggest student sections for this term')
            ->modalDescription(function (): string {
                $term = $this->selectedTerm()->loadMissing('academicYear');
                $ayLabel = $term->academicYear?->label ?? 'this academic year';

                return "Automatically maps each active program's running year levels (1 to program duration) in {$ayLabel} to its assigned intake batch curriculum.";
            })
            ->modalSubmitActionLabel('Create selected sections')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => $this->termId !== null
                && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed())
            ->fillForm(function (): array {
                $suggestions = $this->suggestedSectionsForTerm($this->selectedTerm(), false);

                return [
                    'include_older_batches' => false,
                    'selected_keys' => array_column($suggestions, 'key'),
                    'default_count' => 30,
                    'auto_create_classes' => TermCalendarPackage::query()
                        ->where('term_id', $this->termId)
                        ->where('state', TermCalendarPackage::StateActive)
                        ->exists(),
                ];
            })
            ->schema(function (): array {
                $term = $this->selectedTerm();
                $hasActiveCalendar = TermCalendarPackage::query()
                    ->where('term_id', $term->id)
                    ->where('state', TermCalendarPackage::StateActive)
                    ->exists();

                return [
                    Toggle::make('include_older_batches')
                        ->label('Include older batches (LOA / returnees beyond normal program duration)')
                        ->helperText('By default, lookback uses each program\'s duration (e.g., 2 years back for 3-year diplomas, 3 years back for 4-year degrees).')
                        ->default(false)
                        ->live(),
                    CheckboxList::make('selected_keys')
                        ->label('Suggested student sections')
                        ->options(fn (Get $get): array => collect($this->suggestedSectionsForTerm($term, (bool) ($get('include_older_batches') ?? false)))
                            ->mapWithKeys(fn (array $item): array => [$item['key'] => $item['label']])
                            ->all())
                        ->descriptions(fn (Get $get): array => collect($this->suggestedSectionsForTerm($term, (bool) ($get('include_older_batches') ?? false)))
                            ->mapWithKeys(fn (array $item): array => [$item['key'] => $item['description']])
                            ->all())
                        ->bulkToggleable()
                        ->required()
                        ->helperText(fn (Get $get): string => $this->suggestedSectionsForTerm($term, (bool) ($get('include_older_batches') ?? false)) === []
                            ? 'All running year levels with assigned batch curricula already have student sections in this term.'
                            : 'Only missing program year levels with an assigned Active or Retired batch curriculum are listed.'),
                    TextInput::make('default_count')
                        ->label('Default expected students (when no active student profiles exist yet)')
                        ->integer()
                        ->minValue(1)
                        ->default(30)
                        ->required(),
                    Toggle::make('auto_create_classes')
                        ->label('Auto-load & confirm matching curriculum classes for each created section')
                        ->default($hasActiveCalendar)
                        ->visible($hasActiveCalendar),
                ];
            })
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                $term = $this->selectedTerm();

                if ($this->hasPublishedTimetable($term) || $this->isTermClosed($term)) {
                    Notification::make()
                        ->title('Cannot suggest student sections')
                        ->body('Student sections cannot be added once the timetable is published or the term is closed.')
                        ->danger()
                        ->send();

                    return;
                }

                $includeOlder = (bool) ($data['include_older_batches'] ?? false);
                $selectedKeys = collect($data['selected_keys'] ?? [])->map(fn (mixed $k): string => (string) $k)->flip()->all();
                $defaultCount = max(1, (int) ($data['default_count'] ?? 30));
                $autoCreateClasses = (bool) ($data['auto_create_classes'] ?? false);

                $suggestions = collect($this->suggestedSectionsForTerm($term, $includeOlder))
                    ->filter(fn (array $item): bool => isset($selectedKeys[$item['key']]))
                    ->values();

                if ($suggestions->isEmpty()) {
                    return;
                }

                $createdCohorts = [];
                $createdClassesCount = 0;

                DB::transaction(function () use ($term, $actor, $suggestions, $defaultCount, $autoCreateClasses, &$createdCohorts, &$createdClassesCount): void {
                    foreach ($suggestions as $item) {
                        $headcount = $item['active_students'] > 0 ? $item['active_students'] : $defaultCount;
                        $createdCohorts[] = app(RecordTermCohort::class)->execute($term, $actor, [
                            'curriculum_version_id' => $item['curriculum_version_id'],
                            'year_level' => $item['year_level'],
                            'reference' => $item['reference'],
                            'confirmed_count' => $headcount,
                        ]);
                    }

                    if (! $autoCreateClasses || ! $actor->can('create', TermOffering::class)) {
                        return;
                    }

                    $hasActiveCalendar = TermCalendarPackage::query()
                        ->where('term_id', $term->id)
                        ->where('state', TermCalendarPackage::StateActive)
                        ->exists();

                    if (! $hasActiveCalendar) {
                        return;
                    }

                    $proposal = app(PrepareTermClassProposal::class);
                    $preview = $proposal->preview($term);
                    $rows = [];

                    foreach ($createdCohorts as $cohort) {
                        $yearLevel = $cohort->year_level ?? 1;
                        $entries = $this->confirmableEntriesForCohort($term, (int) $cohort->curriculum_version_id, $yearLevel);
                        foreach ($entries as $entry) {
                            $modes = $entry->courseSpecification?->allowed_modalities ?? [];
                            $rows[] = [
                                'entry_id' => $entry->id,
                                'section_id' => null,
                                'action' => 'confirm',
                                'code' => '',
                                'capacity' => max((int) $cohort->confirmed_count, 1),
                                'source' => Section::SourceRegular,
                                'modality' => $modes[0] ?? TermOffering::ModalityFaceToFace,
                                'authority_reference' => null,
                                'cohorts' => [
                                    ['id' => (int) $cohort->id, 'count' => (int) $cohort->confirmed_count],
                                ],
                            ];
                        }
                    }

                    if ($rows !== []) {
                        $result = $proposal->confirm($term, $actor, $rows, $preview['token']);
                        $createdClassesCount = (int) ($result['confirmed'] ?? 0);
                    }
                }, attempts: 5);

                $sectionCount = count($createdCohorts);
                $body = $createdClassesCount > 0
                    ? "{$sectionCount} ".Str::plural('section', $sectionCount)." created and {$createdClassesCount} ".Str::plural('class', $createdClassesCount).' confirmed from their assigned batch curricula.'
                    : "{$sectionCount} ".Str::plural('section', $sectionCount).' created from their assigned batch curricula.';

                Notification::make()
                    ->title('Suggested student sections added')
                    ->body($body)
                    ->success()
                    ->send();
            });
    }

    protected function maxYearLevelForProgram(?Program $program): int
    {
        $duration = (int) ($program?->duration_years ?? 4);

        return max(1, min(8, $duration > 0 ? $duration : 4));
    }

    protected function maxYearLevelForCurriculum(?CurriculumVersion $curriculum): int
    {
        return $this->maxYearLevelForProgram($curriculum?->program);
    }

    /** @return array<int, string> */
    protected function cohortYearLevelOptionsForProgram(?Program $program): array
    {
        $maxYear = $this->maxYearLevelForProgram($program);
        $options = [];
        for ($year = 1; $year <= $maxYear; $year++) {
            $options[$year] = "Year {$year}";
        }

        return $options;
    }

    /** @return array<int, string> */
    protected function cohortYearLevelOptions(?CurriculumVersion $curriculum): array
    {
        return $this->cohortYearLevelOptionsForProgram($curriculum?->program);
    }

    protected function formatCurriculumOptionLabel(CurriculumVersion $curriculum, bool $includeProgram = false): string
    {
        $batch = $curriculum->batchLabel();
        $name = trim((string) ($curriculum->name ?? ''));
        $core = ($name !== '' && $name !== $batch) ? "{$name} ({$batch})" : $batch;
        $retired = $curriculum->isRetired() ? ' (Retired)' : '';

        return ($includeProgram && $curriculum->program !== null ? $curriculum->program->name.' · ' : '').$core.$retired;
    }

    protected function resolveSingleIntakeBatchLabel(Term $term, int $yearLevel): ?string
    {
        if ($yearLevel <= 0) {
            return null;
        }

        $term->loadMissing('academicYear');
        if (! $term->academicYear instanceof AcademicYear) {
            return null;
        }

        $termStartYear = CurriculumVersion::extractAcademicYearStart($term->academicYear);
        if ($termStartYear === null) {
            return null;
        }

        $intakeStartYear = $termStartYear - ($yearLevel - 1);

        return 'Batch '.$intakeStartYear.'–'.substr((string) ($intakeStartYear + 1), -2);
    }

    protected function resolvedBatchDisplay(Term $term, ?CurriculumVersion $curriculum, ?int $yearLevel): ?string
    {
        if (! $curriculum instanceof CurriculumVersion) {
            return null;
        }

        $term->loadMissing('academicYear');
        $curriculum->loadMissing('academicYears');

        if ($term->academicYear instanceof AcademicYear && $yearLevel !== null && $yearLevel > 0) {
            $termStartYear = CurriculumVersion::extractAcademicYearStart($term->academicYear);
            if ($termStartYear !== null) {
                $intakeStartYear = $termStartYear - ($yearLevel - 1);
                if ($curriculum->coversAcademicYearStart($intakeStartYear)) {
                    return 'Batch '.$intakeStartYear.'–'.substr((string) ($intakeStartYear + 1), -2);
                }
            }
        }

        return $curriculum->batchLabel();
    }

    protected function resolvedBatchDisplayForCohort(TermCohort $cohort, ?Term $term = null): ?string
    {
        return $this->resolvedBatchDisplay(
            $term ?? $this->selectedTerm(),
            $cohort->curriculumVersion,
            $cohort->year_level ?? $cohort->resolvedYearLevel(),
        );
    }

    private function scopedExpectedStudentsHelperText(
        Term $term,
        int $maxRoomSeats,
        ?CurriculumVersion $curriculum,
        ?int $programId,
        int $yearLevel,
    ): string {
        $resolvedProgramId = $programId ?: ($curriculum?->program_id !== null ? (int) $curriculum->program_id : null);
        $resolvedCurriculumId = $curriculum?->id !== null ? (int) $curriculum->id : null;
        $seatHint = $maxRoomSeats > 0
            ? "Largest active room holds {$maxRoomSeats} seats."
            : 'Estimated or confirmed headcount used to check room seating capacity.';

        if ($resolvedProgramId === null && $resolvedCurriculumId === null) {
            return 'Choose a program and year level to see matching batch demand. '.$seatHint;
        }

        $term->loadMissing('academicYear');
        $intakeStartYear = null;
        if ($term->academicYear instanceof AcademicYear && $yearLevel > 0) {
            $termStartYear = CurriculumVersion::extractAcademicYearStart($term->academicYear);
            if ($termStartYear !== null) {
                $intakeStartYear = $termStartYear - ($yearLevel - 1);
            }
        }

        $demandEvidence = app(PrepareTermClassProposal::class)->demandEvidence(
            $term,
            $resolvedProgramId,
            $resolvedCurriculumId,
            intakeStartYear: $intakeStartYear,
        );

        return $this->expectedStudentsHelperText(
            $demandEvidence,
            $maxRoomSeats,
            $yearLevel,
            $this->resolveSingleIntakeBatchLabel($term, $yearLevel),
        );
    }

    /**
     * @param  array{ready_applicants: int, continuing_profiles: int, unmet: list<array<string, mixed>>}  $demandEvidence
     */
    private function expectedStudentsHelperText(array $demandEvidence, int $maxRoomSeats, int $yearLevel, ?string $batchLabel = null): string
    {
        $readyApplicants = (int) ($demandEvidence['ready_applicants'] ?? 0);
        $continuingProfiles = (int) ($demandEvidence['continuing_profiles'] ?? 0);
        $demandHints = [];

        if ($readyApplicants > 0 && $yearLevel <= 1) {
            $demandHints[] = "{$readyApplicants} ready ".Str::plural('applicant', $readyApplicants);
        } elseif ($readyApplicants > 0) {
            $demandHints[] = "{$readyApplicants} ready ".Str::plural('applicant', $readyApplicants).' in program';
        }

        if ($continuingProfiles > 0) {
            $batchSuffix = $batchLabel !== null ? " ({$batchLabel})" : '';
            $demandHints[] = "{$continuingProfiles} continuing ".Str::plural('student', $continuingProfiles).$batchSuffix;
        }

        $demandPrefix = $demandHints !== [] ? 'Live demand: '.implode(' · ', $demandHints).'. ' : '';
        $seatHint = $maxRoomSeats > 0
            ? "Largest active room holds {$maxRoomSeats} seats."
            : 'Estimated or confirmed headcount used to check room seating capacity.';

        return $demandPrefix.$seatHint;
    }

    /** @return Collection<int, CurriculumEntry> */
    private function confirmableEntriesForCohort(Term $term, int $curriculumVersionId, int $yearLevel): Collection
    {
        if ($curriculumVersionId <= 0 || $yearLevel <= 0) {
            return collect();
        }

        $lockedEntryIds = TermOffering::query()
            ->where('term_id', $term->id)
            ->where('delivery_variant', TermOffering::ArrangementNormalClass)
            ->whereIn('state', [TermOffering::StateScheduled, TermOffering::StateCancelled])
            ->pluck('curriculum_entry_id')
            ->filter()
            ->flip()
            ->all();

        return CurriculumEntry::query()
            ->where('curriculum_version_id', $curriculumVersionId)
            ->where('term_type', $term->type)
            ->with(['courseSpecification.course', 'courseSpecification.components'])
            ->orderBy('sequence')
            ->get()
            ->filter(function (CurriculumEntry $entry) use ($yearLevel, $lockedEntryIds): bool {
                if (TermCohort::parseYearLevelValue($entry->year_level) !== $yearLevel) {
                    return false;
                }

                return $this->isConfirmableCurriculumEntry($entry, $lockedEntryIds);
            })
            ->unique(fn (CurriculumEntry $entry): int => (int) ($entry->courseSpecification?->course_id ?? $entry->id))
            ->values();
    }

    /** @param array<int, mixed> $lockedEntryIds */
    protected function isConfirmableCurriculumEntry(CurriculumEntry $entry, array $lockedEntryIds = []): bool
    {
        if (isset($lockedEntryIds[$entry->id])) {
            return false;
        }

        $spec = $entry->courseSpecification;
        if (! $spec instanceof CourseSpecification || $spec->state !== CourseSpecification::StateActive) {
            return false;
        }

        if (empty($spec->allowed_modalities)) {
            return false;
        }

        if ($spec->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged) {
            return true;
        }

        if ($spec->components->isEmpty()) {
            return false;
        }

        return ! $spec->components->contains(function (CourseComponent $component): bool {
            $pattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);

            return $pattern === null
                || (int) round((float) $component->weekly_contact_hours * 60) !== $pattern['count'] * $pattern['duration_minutes'];
        });
    }

    private function syncSuggestedSectionReference(Set $set, Get $get, Term $term, ?CurriculumVersion $curriculum, int $yearLevel): void
    {
        $nextSuggestion = $this->suggestSectionReference($term, $curriculum, $yearLevel);
        $currentRef = RecordTermCohort::normalizeReference((string) ($get('reference') ?? ''));
        $previousSuggestion = RecordTermCohort::normalizeReference((string) ($get('suggested_reference') ?? ''));

        if ($nextSuggestion !== '' && ($currentRef === '' || ($previousSuggestion !== '' && $currentRef === $previousSuggestion))) {
            $set('reference', $nextSuggestion);
        }

        $set('suggested_reference', $nextSuggestion);
    }

    private function suggestSectionReference(Term $term, ?CurriculumVersion $curriculum, int $yearLevel): string
    {
        if (! $curriculum instanceof CurriculumVersion) {
            return '';
        }

        $programCode = Str::upper(trim((string) preg_replace('/\s+/', ' ', (string) ($curriculum->program?->code ?? ''))));
        if ($programCode === '') {
            return '';
        }

        $year = max(1, $yearLevel);
        $existing = TermCohort::query()
            ->where('term_id', $term->id)
            ->pluck('reference')
            ->map(fn (string $reference): string => RecordTermCohort::normalizeReference($reference))
            ->flip()
            ->all();

        $sectionNumber = 1;
        do {
            $candidate = "{$programCode} {$year}-{$sectionNumber}";
            $sectionNumber++;
        } while (isset($existing[$candidate]) && $sectionNumber <= 99);

        return $candidate;
    }

    /**
     * @param  list<int>  $curriculumIds
     * @return array<int, array<string, list<int>>>
     */
    protected function configuredSubjectPeriodsByCurriculum(array $curriculumIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $curriculumIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($normalizedIds === []) {
            return [];
        }

        $entries = CurriculumEntry::query()
            ->whereIn('curriculum_version_id', $normalizedIds)
            ->whereHas('courseSpecification', fn ($query) => $query->where('state', CourseSpecification::StateActive))
            ->get(['curriculum_version_id', 'term_type', 'year_level']);

        $grouped = [];
        foreach ($entries as $entry) {
            $curriculumId = (int) $entry->curriculum_version_id;
            $termType = (string) $entry->term_type;
            $year = TermCohort::parseYearLevelValue($entry->year_level);

            if ($termType === '' || $year === null) {
                continue;
            }

            $grouped[$curriculumId][$termType][$year] = $year;
        }

        $termOrder = [
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
        ];

        foreach ($grouped as $curriculumId => $periods) {
            uksort($periods, static fn (string $a, string $b): int => ($termOrder[$a] ?? 99) <=> ($termOrder[$b] ?? 99));
            foreach ($periods as $termType => $years) {
                $sortedYears = array_values($years);
                sort($sortedYears);
                $periods[$termType] = $sortedYears;
            }
            $grouped[$curriculumId] = $periods;
        }

        return $grouped;
    }

    /**
     * @param  array<string, list<int>>  $periods
     */
    protected function formatConfiguredPeriods(
        array $periods,
        string $style = 'paren',
        ?string $skipTermType = null,
        ?int $skipYearLevel = null,
    ): ?string {
        if ($periods === []) {
            return null;
        }

        $termOptions = Term::typeOptions();
        $parts = [];

        foreach ($periods as $termType => $years) {
            $filteredYears = array_values(array_filter(
                $years,
                static fn (int $year): bool => ! ($skipTermType !== null
                    && $termType === $skipTermType
                    && ($skipYearLevel === null || $year === $skipYearLevel)),
            ));

            if ($filteredYears === []) {
                continue;
            }

            $termLabel = $termOptions[$termType] ?? $termType;
            $yearText = implode(', ', array_map(static fn (int $year): string => "Year {$year}", $filteredYears));

            $parts[] = $style === 'dot'
                ? "{$termLabel} · {$yearText}"
                : "{$termLabel} ({$yearText})";
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /** @return list<string> */
    private function deriveSectionReferences(string $baseReference, int $count, int $yearLevel = 1): array
    {
        $base = RecordTermCohort::normalizeReference(Str::upper(trim((string) preg_replace('/\s+/', ' ', $baseReference))));
        if ($count <= 1 || $base === '') {
            return [$base];
        }

        $resolvedYear = max(1, $yearLevel);

        if (preg_match('/^[A-Z]+$/', $base) === 1) {
            $references = [];
            for ($i = 1; $i <= $count; $i++) {
                $references[] = "{$base} {$resolvedYear}-{$i}";
            }

            return $references;
        }

        if (preg_match('/^(.*[\d\s-])([A-Z])$/', $base, $matches) === 1) {
            $prefix = $matches[1];
            $startOrd = ord($matches[2]);
            $references = [];
            for ($i = 0; $i < $count; $i++) {
                $char = chr(min(ord('Z'), $startOrd + $i));
                $references[] = $startOrd + $i <= ord('Z') ? $prefix.$char : $base.'-'.($i + 1);
            }

            return $references;
        }

        if (preg_match('/^(.*?)(\d+)$/', $base, $matches) === 1) {
            $prefix = $matches[1];
            $startNum = (int) $matches[2];
            $references = [];
            for ($i = 0; $i < $count; $i++) {
                $references[] = $prefix.($startNum + $i);
            }

            return $references;
        }

        $references = [];
        for ($i = 1; $i <= $count; $i++) {
            $references[] = "{$base}-{$i}";
        }

        return $references;
    }

    public function prepareClassOfferingAction(): Action
    {
        return Action::make('prepareClassOffering')

            ->label('Prepare class')
            ->modalHeading('Prepare class')->modalSubmitActionLabel('Save class preparation')->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => $this->termId !== null
                && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed())
            ->modalDescription('Review this class’s subject and active teaching calendar, then confirm its linked student sections. The class reference is generated automatically.')
            ->fillForm(fn (array $arguments, ?Section $record = null): array => ['section_id' => $record?->id ?? $arguments['sectionId'] ?? null, 'class_reference' => $record?->class_reference ?: $record?->code, 'source' => $record?->source, 'delivery_mode' => $record?->delivery_mode])
            ->schema(fn (array $arguments, ?Section $record = null): array => [
                Select::make('section_id')->label('Class')->disabled($record !== null || isset($arguments['sectionId']))->dehydrated()
                    ->options(fn (): array => Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $this->termId))
                        ->whereNull('confirmed_at')->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])->orderBy('code')->get()
                        ->mapWithKeys(fn (Section $section): array => [$section->id => filled($section->code) ? $section->code : "Draft class #{$section->id}"])->all())
                    ->required()->searchable(),
                Hidden::make('class_reference')->dehydrated(),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('source')->options(Section::confirmableSourceOptions())->required()->live(),
                    Select::make('delivery_mode')->label('Delivery mode')->options(TermOffering::modalityOptions())->required(),
                    TextInput::make('authority_reference')
                        ->label('Additional class approval reference')
                        ->helperText('Required for an externally approved Additional class.')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('source') === Section::SourceAdditional)
                        ->required(fn (Get $get): bool => $get('source') === Section::SourceAdditional)
                        ->columnSpanFull(),
                ]),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $term = $this->selectedTerm();
                if ($this->isTermClosed($term) || $this->hasPublishedTimetable($term)) {
                    $message = $this->isTermClosed($term)
                        ? 'This term is closed and classes cannot be prepared.'
                        : 'The official timetable is already published. Use Timetable → Revise official schedule for changes.';
                    Notification::make()
                        ->title('Cannot prepare class')
                        ->body($message)
                        ->danger()
                        ->persistent()
                        ->send();
                    throw ValidationException::withMessages([$schema->getStatePath().'.section_id' => $message]);
                }
                if (($data['source'] ?? null) !== Section::SourceAdditional) {
                    $data['authority_reference'] = null;
                }
                try {
                    app(PrepareClassOffering::class)->execute($term, Section::query()->findOrFail((int) $data['section_id']), $actor, $data);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot prepare class')
                        ->body(is_string($firstMessage) ? $firstMessage : 'Review the class details and try again.')
                        ->danger()
                        ->persistent()
                        ->send();
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all());
                }
                Notification::make()->title('Class prepared')->body('Review its linked student sections, then confirm the class.')->success()->send();
            });
    }
}
