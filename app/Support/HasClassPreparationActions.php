<?php

namespace App\Support;

use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\Scheduling\RecordTermCohort;
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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($term, $activePackage): void {
                                $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                                $yearLevel = (int) ($state ?? 1);
                                $hasMatching = $activePackage !== null
                                    && $curriculumId > 0
                                    && $yearLevel > 0
                                    && $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel)->isNotEmpty();
                                $set('auto_create_classes', $hasMatching);
                            })
                            ->helperText('Recorded on the student section for curriculum matching.'),
                        TextInput::make('reference')
                            ->label('Section name')
                            ->placeholder('e.g., BSIT-1A')
                            ->helperText('Block code shown on timetables (or starting code when creating multiple sections).')
                            ->required()
                            ->maxLength(64),
                    ]),
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
                            ->helperText('Leave as 1, or enter 2–10 to create consecutive blocks (e.g., BSIT-1A, BSIT-1B).'),
                    ]),
                    Checkbox::make('auto_create_classes')
                        ->label('Automatically create & confirm curriculum classes for this section')
                        ->default($activePackage !== null)
                        ->dehydrated()
                        ->visible($activePackage !== null)
                        ->disabled(function (Get $get) use ($term): bool {
                            $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                            $yearLevel = (int) ($get('year_level') ?? 1);
                            if ($curriculumId <= 0 || $yearLevel <= 0) {
                                return false;
                            }

                            return $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel)->isEmpty();
                        })
                        ->helperText(function (Get $get) use ($term): string {
                            $curriculumId = (int) ($get('curriculum_version_id') ?? 0);
                            $yearLevel = (int) ($get('year_level') ?? 1);
                            if ($curriculumId <= 0) {
                                return 'Choose a program curriculum above to load matching subjects for this term in one step.';
                            }
                            $count = $this->confirmableEntriesForCohort($term, $curriculumId, $yearLevel)->count();
                            if ($count === 0) {
                                return 'No confirmable curriculum subjects match this year level and term period.';
                            }

                            return "Creates and confirms {$count} matching curriculum ".Str::plural('class', $count).' for this student section in one step.';
                        }),
                    Placeholder::make('auto_create_classes_notice')
                        ->hiddenLabel()
                        ->content('Activate the term calendar in Step 1 to auto-create classes from curriculum.')
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
                $references = $this->deriveSectionReferences((string) ($data['reference'] ?? ''), $sectionCount);
                $resolvedYearLevel = filled($data['year_level'] ?? null)
                    ? (int) $data['year_level']
                    : (TermCohort::parseYearLevelValue($data['reference'] ?? null) ?? 1);
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
            ->with(['courseSpecification.components'])
            ->orderBy('sequence')
            ->get()
            ->filter(function (CurriculumEntry $entry) use ($yearLevel, $lockedEntryIds): bool {
                if (isset($lockedEntryIds[$entry->id])) {
                    return false;
                }

                if (TermCohort::parseYearLevelValue($entry->year_level) !== $yearLevel) {
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
            })
            ->unique(fn (CurriculumEntry $entry): int => (int) ($entry->courseSpecification?->course_id ?? $entry->id))
            ->values();
    }

    /** @return list<string> */
    private function deriveSectionReferences(string $baseReference, int $count): array
    {
        $base = trim($baseReference);
        if ($count <= 1 || $base === '') {
            return [$base];
        }

        if (preg_match('/^(.*?)([A-Z])$/', $base, $matches) === 1) {
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
