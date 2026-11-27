<?php

namespace App\Support;

use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\Scheduling\RecordTermCohort;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
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
            ->modalDescription(fn (): string => $this->selectedTerm()->label.'. Choose the program curriculum, year level, and expected student headcount.')
            ->schema(function (): array {
                $term = $this->selectedTerm();
                $activePackage = TermCalendarPackage::query()
                    ->where('term_id', $term->id)
                    ->where('state', TermCalendarPackage::StateActive)
                    ->first();
                $maxRoomSeats = (int) (Room::query()->where('is_active', true)->max('capacity') ?? 0);
                $demandEvidence = app(PrepareTermClassProposal::class)->demandEvidence($term);
                $curricula = CurriculumVersion::query()
                    ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                    ->with(['program', 'batches.academicYear'])
                    ->get()
                    ->keyBy('id');

                return [
                    Hidden::make('suggested_reference')->dehydrated(false),
                    Select::make('curriculum_version_id')
                        ->label('Program & curriculum')
                        ->placeholder('Choose a program curriculum')
                        ->options(fn (): array => $curricula
                            ->mapWithKeys(fn (CurriculumVersion $curriculum): array => [
                                $curriculum->id => $curriculum->program?->name.' · '.$curriculum->batchLabel().($curriculum->isRetired() ? ' (Retired)' : ''),
                            ])->all())
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($term, $activePackage, $curricula): void {
                            $curriculum = $curricula->get((int) $state);
                            $maxYear = $this->maxYearLevelForCurriculum($curriculum);
                            $currentYear = (int) ($get('year_level') ?? 1);
                            $resolvedYear = ($currentYear >= 1 && $currentYear <= $maxYear) ? $currentYear : 1;
                            $set('year_level', $resolvedYear);
                            $this->syncSuggestedSectionReference($set, $get, $term, $curriculum, $resolvedYear);

                            $hasMatching = $activePackage !== null
                                && $curriculum instanceof CurriculumVersion
                                && $this->confirmableEntriesForCohort($term, $curriculum->id, $resolvedYear)->isNotEmpty();
                            $set('auto_create_classes', $hasMatching);
                        }),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('year_level')
                            ->label('Year level')
                            ->options(fn (Get $get): array => $this->cohortYearLevelOptions($curricula->get((int) $get('curriculum_version_id'))))
                            ->default(1)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($term, $activePackage, $curricula): void {
                                $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                                $curriculum = $curricula->get($curriculumId);
                                $yearLevel = max(1, (int) ($state ?? 1));
                                $this->syncSuggestedSectionReference($set, $get, $term, $curriculum, $yearLevel);

                                $hasMatching = $activePackage !== null
                                    && $curriculumId > 0
                                    && $yearLevel > 0
                                    && $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel)->isNotEmpty();
                                $set('auto_create_classes', $hasMatching);
                            })
                            ->helperText('Recorded on the student section for curriculum matching.'),
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
                    ]),
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
                            ->helperText(fn (Get $get): string => $this->expectedStudentsHelperText($demandEvidence, $maxRoomSeats, (int) ($get('year_level') ?? 1)))
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

    private function maxYearLevelForCurriculum(?CurriculumVersion $curriculum): int
    {
        $duration = (int) ($curriculum?->program?->duration_years ?? 4);

        return max(1, min(8, $duration > 0 ? $duration : 4));
    }

    /** @return array<int, string> */
    private function cohortYearLevelOptions(?CurriculumVersion $curriculum): array
    {
        $maxYear = $this->maxYearLevelForCurriculum($curriculum);
        $options = [];
        for ($year = 1; $year <= $maxYear; $year++) {
            $options[$year] = "Year {$year}";
        }

        return $options;
    }

    /**
     * @param  array{ready_applicants: int, continuing_profiles: int, unmet: list<array<string, mixed>>}  $demandEvidence
     */
    private function expectedStudentsHelperText(array $demandEvidence, int $maxRoomSeats, int $yearLevel): string
    {
        $readyApplicants = (int) ($demandEvidence['ready_applicants'] ?? 0);
        $continuingProfiles = (int) ($demandEvidence['continuing_profiles'] ?? 0);
        $demandHints = [];

        if ($readyApplicants > 0 && $yearLevel <= 1) {
            $demandHints[] = "{$readyApplicants} ready ".Str::plural('applicant', $readyApplicants);
        } elseif ($readyApplicants > 0) {
            $demandHints[] = "{$readyApplicants} ready ".Str::plural('applicant', $readyApplicants).' term-wide';
        }

        if ($continuingProfiles > 0) {
            $demandHints[] = "{$continuingProfiles} continuing ".Str::plural('student', $continuingProfiles);
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
