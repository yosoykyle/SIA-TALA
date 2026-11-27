<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Enrollment\RegistrationWindowApplicability;
use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Models\AcademicDecision;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProgramShiftEnrollmentForm
{
    public const StatusNeed = 'need';

    public const StatusCredit = 'credit';

    /** @return list<Step> */
    public static function steps(): array
    {
        $invalidate = fn (Set $set): mixed => $set('impact_confirmed', false);

        return [
            Step::make('Student & target program')
                ->description('Choose student, term, and destination program')
                ->schema([
                    Select::make('student_profile_id')
                        ->label('Student')
                        ->placeholder('Search name or student number')
                        ->relationship(
                            'studentProfile',
                            'student_number',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->active()
                                ->where('lifecycle_status', StudentProfile::LifecycleActive)
                                ->with(['program', 'curriculumVersion'])
                                ->orderBy('student_number'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (StudentProfile $record): string => collect([
                            $record->student_number,
                            trim($record->first_name.' '.$record->last_name),
                            $record->program?->code,
                        ])->filter()->implode(' · '))
                        ->searchable(['student_number', 'first_name', 'middle_name', 'last_name'])
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            $set('target_program_id', null);
                            $set('target_curriculum_version_id', null);
                            $set('credit_rows', []);
                            $set('impact_confirmed', false);
                            $set('apply_and_enroll', self::canApplyAndEnrollNow(
                                filled($state) ? (int) $state : null,
                                filled($get('term_id')) ? (int) $get('term_id') : null,
                            ));
                        }),

                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        Select::make('term_id')
                            ->label('Effective term')
                            ->placeholder('Choose a term')
                            ->options(fn (): array => self::termOptions())
                            ->searchable()
                            ->live()
                            ->required()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $term = filled($state) ? Term::query()->find((int) $state) : null;
                                if ($term instanceof Term) {
                                    $set('effective_on', self::defaultEffectiveOn($term));
                                }
                                $set('impact_confirmed', false);
                                $set('apply_and_enroll', self::canApplyAndEnrollNow(
                                    filled($get('student_profile_id')) ? (int) $get('student_profile_id') : null,
                                    filled($state) ? (int) $state : null,
                                ));
                            }),
                        DatePicker::make('effective_on')
                            ->label('Effective date')
                            ->default(fn (): string => today('Asia/Manila')->toDateString())
                            ->hidden(fn (Get $get): bool => self::existingPendingShiftFromGet($get) !== null)
                            ->required(fn (Get $get): bool => self::existingPendingShiftFromGet($get) === null)
                            ->live()
                            ->afterStateUpdated($invalidate),
                    ]),

                    SchemaView::make('filament.admin.enrollments.program-shift-student-context')
                        ->viewData(fn (Get $get): array => self::studentContextViewData($get))
                        ->visible(fn (Get $get): bool => filled($get('student_profile_id'))),

                    Group::make([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            Select::make('target_program_id')
                                ->label('Target program')
                                ->placeholder('Choose a program')
                                ->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->live()
                                ->required(fn (Get $get): bool => self::existingPendingShiftFromGet($get) === null)
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('target_curriculum_version_id', null);
                                    $set('credit_rows', []);
                                    $set('impact_confirmed', false);
                                }),
                            Select::make('target_curriculum_version_id')
                                ->label('Target curriculum')
                                ->placeholder('Choose an active curriculum')
                                ->options(fn (Get $get): array => self::targetCurriculumOptions($get))
                                ->searchable()
                                ->live()
                                ->required(fn (Get $get): bool => self::existingPendingShiftFromGet($get) === null)
                                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (self::existingPendingShiftFromGet($get) !== null || blank($value)) {
                                        return;
                                    }

                                    $student = StudentProfile::query()->find($get('student_profile_id'));
                                    if ($student instanceof StudentProfile
                                        && (int) $get('target_program_id') === (int) $student->program_id
                                        && (int) $value === (int) $student->curriculum_version_id) {
                                        $fail('Choose a different program or curriculum version from the student’s current record.');
                                    }
                                })
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('credit_rows', []);
                                    $set('impact_confirmed', false);
                                }),
                        ]),
                        Textarea::make('reason')
                            ->label('Reason for change')
                            ->placeholder('Approved program shift request or Registrar basis')
                            ->rows(2)
                            ->maxLength(2000)
                            ->required(fn (Get $get): bool => self::existingPendingShiftFromGet($get) === null)
                            ->live(onBlur: true)
                            ->afterStateUpdated($invalidate),
                        Section::make('Outside program shift window')
                            ->description('This term does not have an open program shift or enrollment window. Record the approved late exception details.')
                            ->compact()
                            ->visible(fn (Get $get): bool => self::needsLateException($get))
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 2])->schema([
                                    TextInput::make('late_exception_authority')
                                        ->label('Late exception authority')
                                        ->maxLength(255)
                                        ->required(fn (Get $get): bool => self::needsLateException($get))
                                        ->live(onBlur: true)
                                        ->afterStateUpdated($invalidate),
                                    Textarea::make('late_exception_reason')
                                        ->label('Reason for late exception')
                                        ->rows(2)
                                        ->maxLength(2000)
                                        ->required(fn (Get $get): bool => self::needsLateException($get))
                                        ->live(onBlur: true)
                                        ->afterStateUpdated($invalidate),
                                ]),
                            ]),
                    ])
                        ->visible(fn (Get $get): bool => self::existingPendingShiftFromGet($get) === null),
                ]),

            Step::make('Credit mapping & confirm')
                ->description('Map released SIA credits and confirm effects')
                ->schema([
                    Group::make()
                        ->visible(fn (Get $get): bool => self::existingPendingShiftFromGet($get) === null)
                        ->schema(fn (Get $get): array => self::creditMappingComponents($get)),

                    SchemaView::make('filament.admin.enrollments.program-shift-effects')
                        ->viewData(fn (Get $get): array => self::effectsViewData($get)),

                    Checkbox::make('apply_and_enroll')
                        ->label('Apply shift and start enrollment now')
                        ->helperText(fn (Get $get): string => self::applyAndEnrollHelperText($get))
                        ->default(true)
                        ->live()
                        ->visible(fn (Get $get): bool => self::existingPendingShiftFromGet($get) === null)
                        ->disabled(fn (Get $get): bool => ! self::canApplyAndEnrollNow(
                            filled($get('student_profile_id')) ? (int) $get('student_profile_id') : null,
                            filled($get('term_id')) ? (int) $get('term_id') : null,
                        ))
                        ->dehydrated(),

                    Checkbox::make('impact_confirmed')
                        ->label('I reviewed these effects.')
                        ->helperText('Your name and the time are recorded automatically.')
                        ->accepted()
                        ->required()
                        ->dehydrated(false),
                ]),
        ];
    }

    public static function existingPendingShift(?int $studentProfileId, ?int $termId): ?StudentLifecycleChange
    {
        if ($studentProfileId === null || $termId === null) {
            return null;
        }

        return StudentLifecycleChange::query()
            ->with(['targetProgram', 'targetCurriculumVersion', 'term', 'programShiftCredits'])
            ->where('student_profile_id', $studentProfileId)
            ->where('term_id', $termId)
            ->where('type', StudentLifecycleChange::TypeProgramShift)
            ->where('state', StudentLifecycleChange::StateRecordedApproved)
            ->latest('id')
            ->first();
    }

    public static function defaultEffectiveOn(Term $term): string
    {
        $calendar = app(CalendarPhaseGateService::class);
        $enrollmentWindow = $calendar->isCutoverActive((int) $term->id)
            ? $calendar->enrollmentWindow((int) $term->id)
            : null;

        $earliest = $enrollmentWindow?->opens_on?->lt($term->starts_on)
            ? $enrollmentWindow->opens_on
            : $term->starts_on;

        $today = today('Asia/Manila');

        return $today->greaterThanOrEqualTo($earliest)
            ? $today->toDateString()
            : $earliest->toDateString();
    }

    /**
     * Converts the form's credit_rows map into the payload expected by ShiftProgramAndStartEnrollment.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{curriculum_entry_id: int, treatment: string, source_grade_outcome_event_id: ?int}>
     */
    public static function creditEntries(array $data): array
    {
        return collect($data['credit_rows'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && ($row['status'] ?? self::StatusNeed) === self::StatusCredit)
            ->map(fn (array $row, string $key): array => [
                'curriculum_entry_id' => (int) substr($key, 1),
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                'source_grade_outcome_event_id' => filled($row['source_grade_outcome_event_id'] ?? null)
                    ? (int) $row['source_grade_outcome_event_id']
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Checks whether applying the shift and starting enrollment right now is allowed.
     *
     * @return array{can_apply: bool, reason: ?string}
     */
    public static function enrollmentStartReadiness(?int $studentProfileId, ?int $termId): array
    {
        if ($termId === null) {
            return ['can_apply' => false, 'reason' => 'Choose a term first.'];
        }

        $term = Term::query()->find($termId);
        if (! $term instanceof Term || $term->state === Term::StateClosed) {
            return ['can_apply' => false, 'reason' => 'This term is closed and does not accept new enrollments.'];
        }

        if (! app(CalendarPhaseGateService::class)->isCutoverActive((int) $term->id)) {
            $startsOn = $term->starts_on->toDateString();
            if ($startsOn > today('Asia/Manila')->toDateString()) {
                return [
                    'can_apply' => false,
                    'reason' => 'Enrollment is not open yet for this future term. The shift will be recorded as approved and can be applied when enrollment opens.',
                ];
            }

            return [
                'can_apply' => false,
                'reason' => 'The enrollment window for this term is closed. Applying and starting enrollment now is unavailable.',
            ];
        }

        if (! app(RegistrationWindowApplicability::class)->permits($term, Enrollment::SelectionIndividuallyAdvised, false)) {
            return [
                'can_apply' => false,
                'reason' => 'This term’s allowed-students setting does not currently permit individually advised enrollments.',
            ];
        }

        if ($studentProfileId === null) {
            return ['can_apply' => true, 'reason' => null];
        }

        $student = StudentProfile::query()->find($studentProfileId);
        if (! $student instanceof StudentProfile) {
            return ['can_apply' => false, 'reason' => 'Choose an active student.'];
        }

        if ($student->blocksEnrollmentByLifecycle()) {
            return ['can_apply' => false, 'reason' => 'The student’s lifecycle status does not allow enrollment.'];
        }

        $existingEnrollment = self::existingTermEnrollment($student, $term);
        if ($existingEnrollment instanceof Enrollment) {
            return [
                'can_apply' => false,
                'reason' => 'This student already has an enrollment record in this term. Resolve or cancel it before starting a new enrollment.',
            ];
        }

        $holds = app(HoldEvaluationService::class);
        $blockingHold = $holds->mostRestrictiveActiveHold($student, [Hold::BlockingEnrollment], term: $term);
        if ($blockingHold instanceof Hold) {
            return ['can_apply' => false, 'reason' => $holds->formatBlockingMessage($blockingHold)];
        }

        $effect = app(AcademicEnrollmentEffect::class)->forStudent($student, $term);
        if (in_array($effect['effect'], [AcademicDecision::EffectBlocked, AcademicDecision::EffectPendingDecision], true)) {
            return ['can_apply' => false, 'reason' => (string) $effect['reason']];
        }

        return ['can_apply' => true, 'reason' => null];
    }

    public static function canApplyAndEnrollNow(?int $studentProfileId, ?int $termId): bool
    {
        return self::enrollmentStartReadiness($studentProfileId, $termId)['can_apply'];
    }

    private static function existingPendingShiftFromGet(Get $get): ?StudentLifecycleChange
    {
        $studentId = filled($get('student_profile_id')) ? (int) $get('student_profile_id') : null;
        $termId = filled($get('term_id')) ? (int) $get('term_id') : null;

        return self::existingPendingShift($studentId, $termId);
    }

    private static function needsLateException(Get $get): bool
    {
        if (self::existingPendingShiftFromGet($get) !== null || blank($get('term_id'))) {
            return false;
        }

        $term = Term::query()->find((int) $get('term_id'));

        return $term instanceof Term
            && ! app(StudentLifecycleService::class)->isWindowOpen($term, StudentLifecycleChange::TypeProgramShift);
    }

    /** @return array<int, string> */
    private static function termOptions(): array
    {
        $calendar = app(CalendarPhaseGateService::class);
        $today = today('Asia/Manila')->toDateString();

        return Term::query()
            ->with('academicYear')
            ->where('state', '!=', Term::StateClosed)
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(function (Term $term) use ($calendar, $today): array {
                $status = match (true) {
                    $calendar->isCutoverActive((int) $term->id) => 'Enrollment open',
                    $term->starts_on->toDateString() > $today => 'Future term',
                    default => 'Enrollment closed',
                };

                $label = collect([
                    $term->label,
                    $term->academicYear?->label,
                    $status,
                ])->filter()->implode(' · ');

                return [$term->id => $label];
            })
            ->all();
    }

    /** @return array<int, string> */
    private static function targetCurriculumOptions(Get $get): array
    {
        if (blank($get('target_program_id'))) {
            return [];
        }

        $targetProgramId = (int) $get('target_program_id');
        $student = filled($get('student_profile_id'))
            ? StudentProfile::query()->find((int) $get('student_profile_id'))
            : null;

        return CurriculumVersion::query()
            ->where('program_id', $targetProgramId)
            ->where('state', CurriculumVersion::StateActive)
            ->when(
                $student instanceof StudentProfile && (int) $student->program_id === $targetProgramId && $student->curriculum_version_id !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($student->curriculum_version_id),
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (CurriculumVersion $version): array => [
                $version->id => $version->name.' ('.$version->version_code.')',
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private static function studentContextViewData(Get $get): array
    {
        $student = filled($get('student_profile_id'))
            ? StudentProfile::query()->with(['program', 'curriculumVersion'])->find((int) $get('student_profile_id'))
            : null;
        $term = filled($get('term_id')) ? Term::query()->find((int) $get('term_id')) : null;

        if (! $student instanceof StudentProfile) {
            return [
                'student' => null,
                'term' => $term,
                'existingShift' => null,
                'existingEnrollment' => null,
                'holdWarnings' => [],
                'passedSiaCount' => 0,
                'hasPriorTransferCredits' => false,
            ];
        }

        $holds = app(HoldEvaluationService::class);
        $holdWarnings = $holds->activeBlockingHolds($student, [Hold::BlockingEnrollment], term: $term)
            ->map(fn (Hold $hold): string => $holds->formatBlockingMessage($hold))
            ->values()
            ->all();

        $hasPriorTransferCredits = RegistrationCreditEvaluation::query()
            ->whereHas('enrollment', fn (Builder $query): Builder => $query->where('student_profile_id', $student->id))
            ->exists();

        return [
            'student' => $student,
            'term' => $term,
            'existingShift' => $term instanceof Term ? self::existingPendingShift($student->id, $term->id) : null,
            'existingEnrollment' => $term instanceof Term ? self::existingTermEnrollment($student, $term) : null,
            'holdWarnings' => $holdWarnings,
            'passedSiaCount' => app(StudentLifecycleService::class)->programShiftSources($student)->count(),
            'hasPriorTransferCredits' => $hasPriorTransferCredits,
        ];
    }

    /** @return list<mixed> */
    private static function creditMappingComponents(Get $get): array
    {
        $student = filled($get('student_profile_id'))
            ? StudentProfile::query()->with(['program', 'curriculumVersion'])->find((int) $get('student_profile_id'))
            : null;
        $targetCurriculum = filled($get('target_curriculum_version_id'))
            ? CurriculumVersion::query()->with('program')->find((int) $get('target_curriculum_version_id'))
            : null;

        if (! $student instanceof StudentProfile || ! $targetCurriculum instanceof CurriculumVersion) {
            return [
                Text::make('Choose a student and target curriculum in Step 1 to map released SIA credits.'),
            ];
        }

        $suggestions = app(StudentLifecycleService::class)->matchingSiaCreditSuggestions($student, $targetCurriculum);
        $alreadySatisfiedByEntry = collect($suggestions['already_satisfied'])->keyBy('curriculum_entry_id');
        $alreadySatisfiedEventIds = $alreadySatisfiedByEntry
            ->pluck('source_grade_outcome_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $equivalentByEntry = collect($suggestions['equivalent_matches'])->keyBy('curriculum_entry_id');
        $sourceOptions = self::sourceOptions($suggestions['available_sources']);

        $entries = CurriculumEntry::query()
            ->with('courseSpecification.course')
            ->where('curriculum_version_id', $targetCurriculum->id)
            ->get()
            ->sortBy([
                fn (CurriculumEntry $a, CurriculumEntry $b): int => self::yearRank($a->year_level) <=> self::yearRank($b->year_level),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => self::termRank($a->term_label) <=> self::termRank($b->term_label),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => [(int) $a->sequence, $a->id] <=> [(int) $b->sequence, $b->id],
            ])
            ->values();

        $components = [
            Text::make(sprintf(
                '%s (%s) → %s (%s)',
                $student->program?->name ?? 'Current program',
                $student->curriculumVersion?->version_code ?? $student->curriculumVersion?->name ?? 'Current',
                $targetCurriculum->program?->name ?? 'Target program',
                $targetCurriculum->version_code ?? $targetCurriculum->name,
            ))->weight('bold'),
        ];

        if ($equivalentByEntry->isNotEmpty()) {
            $equivalentMatches = $suggestions['equivalent_matches'];
            $count = count($equivalentMatches);
            $components[] = Actions::make([
                Action::make('preselectEquivalentMatches')
                    ->label("Pre-select equivalent SIA matches ({$count})")
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->action(function (Get $get, Set $set) use ($equivalentMatches, $alreadySatisfiedEventIds): void {
                        $rows = is_array($get('credit_rows')) ? $get('credit_rows') : [];
                        $usedEventIds = collect($rows)
                            ->filter(fn (mixed $row): bool => is_array($row) && ($row['status'] ?? null) === self::StatusCredit && filled($row['source_grade_outcome_event_id'] ?? null))
                            ->pluck('source_grade_outcome_event_id')
                            ->map(fn (mixed $id): int => (int) $id)
                            ->merge($alreadySatisfiedEventIds)
                            ->unique()
                            ->values()
                            ->all();

                        foreach ($equivalentMatches as $match) {
                            $entryKey = 'e'.$match['curriculum_entry_id'];
                            $eventId = (int) $match['source_grade_outcome_event_id'];
                            $currentRow = is_array($rows[$entryKey] ?? null) ? $rows[$entryKey] : [];

                            if (($currentRow['status'] ?? null) === self::StatusCredit && filled($currentRow['source_grade_outcome_event_id'] ?? null)) {
                                continue;
                            }

                            if (in_array($eventId, $usedEventIds, true)) {
                                continue;
                            }

                            $rows[$entryKey] = [
                                'status' => self::StatusCredit,
                                'source_grade_outcome_event_id' => $eventId,
                            ];
                            $usedEventIds[] = $eventId;
                        }

                        $set('credit_rows', $rows);
                        $set('impact_confirmed', false);
                    }),
            ]);
        }

        if ($entries->isEmpty()) {
            $components[] = Text::make('This target curriculum has no subjects recorded yet.');

            return $components;
        }

        $groupedSections = $entries
            ->groupBy(fn (CurriculumEntry $entry): string => collect([
                is_numeric($entry->year_level) ? 'Year '.$entry->year_level : $entry->year_level,
                $entry->term_label,
            ])->filter()->implode(' · ') ?: 'Curriculum subjects')
            ->map(function (Collection $termEntries, string $groupLabel) use ($alreadySatisfiedByEntry, $equivalentByEntry, $sourceOptions, $alreadySatisfiedEventIds): Section {
                $rowComponents = [];

                foreach ($termEntries as $entry) {
                    $satisfied = $alreadySatisfiedByEntry->get($entry->id);
                    if (is_array($satisfied)) {
                        $units = number_format((float) $satisfied['credit_units'], 2, '.', '');
                        $rowComponents[] = Grid::make(['default' => 1, 'lg' => 12])->schema([
                            Text::make("{$satisfied['course_code']} · {$satisfied['course_title']} · {$units} units")
                                ->weight('medium')
                                ->columnSpan(['lg' => 7]),
                            Text::make("Already satisfied by SIA record · {$satisfied['numeric_grade']}")
                                ->color('success')
                                ->columnSpan(['lg' => 5]),
                        ]);

                        continue;
                    }

                    $rowComponents[] = self::creditRow(
                        $entry,
                        $equivalentByEntry->get($entry->id),
                        $sourceOptions,
                        $alreadySatisfiedEventIds,
                    );
                }

                return Section::make($groupLabel)
                    ->compact()
                    ->schema([
                        Group::make()
                            ->statePath('credit_rows')
                            ->schema($rowComponents),
                    ]);
            })
            ->values()
            ->all();

        return [...$components, ...$groupedSections];
    }

    /**
     * @param  array<string, mixed>|null  $equivalentMatch
     * @param  array<int, string>  $sourceOptions
     * @param  list<int>  $alreadySatisfiedEventIds
     */
    private static function creditRow(
        CurriculumEntry $entry,
        ?array $equivalentMatch,
        array $sourceOptions,
        array $alreadySatisfiedEventIds,
    ): Group {
        $specification = $entry->courseSpecification;
        $code = (string) ($specification?->course?->code ?? 'Course');
        $title = (string) ($specification?->title ?? '');
        $units = number_format((float) ($specification?->credit_units ?? 0.0), 2, '.', '');
        $label = "{$code} · {$title} · {$units} units";

        if (is_array($equivalentMatch)) {
            $label .= " · Equivalent passed at SIA: {$equivalentMatch['source_course_code']} ({$equivalentMatch['numeric_grade']})";
        }

        return Group::make()
            ->statePath('e'.$entry->id)
            ->schema([
                Grid::make(['default' => 1, 'lg' => 12])->schema([
                    Text::make($label)
                        ->weight('medium')
                        ->columnSpan(['lg' => 7]),
                    ToggleButtons::make('status')
                        ->hiddenLabel()
                        ->inline()
                        ->grouped()
                        ->options([
                            self::StatusNeed => 'Need to take',
                            self::StatusCredit => 'Credit',
                        ])
                        ->default(self::StatusNeed)
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if ($state !== self::StatusCredit) {
                                $set('source_grade_outcome_event_id', null);
                            }
                            $set('../../impact_confirmed', false);
                        })
                        ->columnSpan(['lg' => 5]),
                ]),
                Select::make('source_grade_outcome_event_id')
                    ->label('Released SIA result')
                    ->placeholder($sourceOptions === [] ? 'No released passing SIA results available' : 'Choose a released passing SIA result')
                    ->options($sourceOptions)
                    ->disableOptionWhen(fn (string $value, Get $get): bool => self::isSourceEventUnavailable(
                        (int) $value,
                        (int) $entry->id,
                        $alreadySatisfiedEventIds,
                        (array) $get('../../credit_rows'),
                    ))
                    ->searchable()
                    ->live()
                    ->visible(fn (Get $get): bool => $get('status') === self::StatusCredit)
                    ->required(fn (Get $get): bool => $get('status') === self::StatusCredit)
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($entry, $alreadySatisfiedEventIds, $get): void {
                        if ($get('status') !== self::StatusCredit || blank($value)) {
                            return;
                        }

                        if (self::isSourceEventUnavailable((int) $value, (int) $entry->id, $alreadySatisfiedEventIds, (array) $get('../../credit_rows'))) {
                            $fail('This released passing result is already assigned to another subject.');
                        }
                    })
                    ->afterStateUpdated(fn (Set $set): mixed => $set('../../impact_confirmed', false)),
            ]);
    }

    /**
     * @param  list<int>  $alreadySatisfiedEventIds
     * @param  array<string, mixed>  $creditRows
     */
    private static function isSourceEventUnavailable(
        int $eventId,
        int $currentEntryId,
        array $alreadySatisfiedEventIds,
        array $creditRows,
    ): bool {
        if (in_array($eventId, $alreadySatisfiedEventIds, true)) {
            return true;
        }

        $currentKey = 'e'.$currentEntryId;
        foreach ($creditRows as $key => $row) {
            if ($key === $currentKey || ! is_array($row)) {
                continue;
            }

            if (($row['status'] ?? null) === self::StatusCredit
                && (int) ($row['source_grade_outcome_event_id'] ?? 0) === $eventId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{source_grade_outcome_event_id: int, source_course_id: int, source_course_code: string, source_course_title: string, numeric_grade: string, term_label_taken: ?string}>  $sources
     * @return array<int, string>
     */
    private static function sourceOptions(array $sources): array
    {
        return collect($sources)
            ->mapWithKeys(fn (array $source): array => [
                $source['source_grade_outcome_event_id'] => collect([
                    $source['source_course_code'],
                    $source['source_course_title'],
                    $source['numeric_grade'],
                    $source['term_label_taken'],
                ])->filter()->implode(' · '),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private static function effectsViewData(Get $get): array
    {
        $studentId = filled($get('student_profile_id')) ? (int) $get('student_profile_id') : null;
        $termId = filled($get('term_id')) ? (int) $get('term_id') : null;
        $student = $studentId !== null
            ? StudentProfile::query()->with(['program', 'curriculumVersion'])->find($studentId)
            : null;
        $existingShift = self::existingPendingShift($studentId, $termId);
        $readiness = self::enrollmentStartReadiness($studentId, $termId);

        if ($existingShift instanceof StudentLifecycleChange) {
            return [
                'state' => ['valid' => true, 'preview' => [], 'message' => ''],
                'student' => $student,
                'existingShift' => $existingShift,
                'canApplyNow' => $readiness['can_apply'],
                'applyBlockedReason' => $readiness['reason'],
                'applyAndEnroll' => $readiness['can_apply'],
                'alreadySatisfiedCount' => 0,
                'acceptedCreditCount' => 0,
                'remainingCount' => 0,
            ];
        }

        $creditEntries = self::creditEntries(['credit_rows' => $get('credit_rows') ?? []]);
        $targetCurriculum = filled($get('target_curriculum_version_id'))
            ? CurriculumVersion::query()->find((int) $get('target_curriculum_version_id'))
            : null;

        $alreadySatisfiedCount = 0;
        $totalCurriculumEntries = 0;
        if ($student instanceof StudentProfile && $targetCurriculum instanceof CurriculumVersion) {
            $suggestions = app(StudentLifecycleService::class)->matchingSiaCreditSuggestions($student, $targetCurriculum);
            $alreadySatisfiedCount = count($suggestions['already_satisfied']);
            $totalCurriculumEntries = CurriculumEntry::query()
                ->where('curriculum_version_id', $targetCurriculum->id)
                ->count();
        }
        $acceptedCreditCount = count($creditEntries);
        $remainingCount = max(0, $totalCurriculumEntries - $alreadySatisfiedCount - $acceptedCreditCount);

        $state = self::previewState($get, $creditEntries);

        return [
            'state' => $state,
            'student' => $student,
            'existingShift' => null,
            'canApplyNow' => $readiness['can_apply'],
            'applyBlockedReason' => $readiness['reason'],
            'applyAndEnroll' => $readiness['can_apply'] && (bool) ($get('apply_and_enroll') ?? false),
            'alreadySatisfiedCount' => $alreadySatisfiedCount,
            'acceptedCreditCount' => $acceptedCreditCount,
            'remainingCount' => $remainingCount,
        ];
    }

    /**
     * @param  list<array{curriculum_entry_id: int, treatment: string, source_grade_outcome_event_id: ?int}>  $creditEntries
     * @return array{valid: bool, preview: array<string, mixed>, message: string}
     */
    private static function previewState(Get $get, array $creditEntries): array
    {
        if (blank($get('student_profile_id'))
            || blank($get('term_id'))
            || blank($get('target_program_id'))
            || blank($get('target_curriculum_version_id'))
            || blank($get('effective_on'))) {
            return [
                'valid' => false,
                'preview' => [],
                'message' => 'Complete the student, term, effective date, target program, and target curriculum to review effects.',
            ];
        }

        try {
            $preview = app(StudentLifecycleService::class)->preview([
                'student_profile_id' => (int) $get('student_profile_id'),
                'term_id' => (int) $get('term_id'),
                'type' => StudentLifecycleChange::TypeProgramShift,
                'effective_on' => $get('effective_on'),
                'reason' => $get('reason'),
                'target_program_id' => (int) $get('target_program_id'),
                'target_curriculum_version_id' => (int) $get('target_curriculum_version_id'),
                'late_exception_authority' => $get('late_exception_authority'),
                'late_exception_reason' => $get('late_exception_reason'),
                'credit_entries' => $creditEntries,
            ]);

            return [
                'valid' => true,
                'preview' => $preview,
                'message' => '',
            ];
        } catch (StudentLifecycleRuleViolation $exception) {
            return [
                'valid' => false,
                'preview' => [],
                'message' => $exception->getMessage(),
            ];
        }
    }

    private static function applyAndEnrollHelperText(Get $get): string
    {
        $readiness = self::enrollmentStartReadiness(
            filled($get('student_profile_id')) ? (int) $get('student_profile_id') : null,
            filled($get('term_id')) ? (int) $get('term_id') : null,
        );

        if (! $readiness['can_apply']) {
            return $readiness['reason'] ?? 'Cannot apply and start enrollment right now. Uncheck to record the shift for later application.';
        }

        return 'Applies the shift immediately and opens an individually advised enrollment in Prepare subjects.';
    }

    private static function existingTermEnrollment(StudentProfile $student, Term $term): ?Enrollment
    {
        return Enrollment::query()
            ->where('term_id', $term->id)
            ->where(fn (Builder $query): Builder => $query
                ->where('student_profile_id', $student->id)
                ->orWhere('credential_user_id', $student->user_id))
            ->latest('id')
            ->first();
    }

    private static function yearRank(?string $yearLevel): int
    {
        $label = strtolower(trim((string) $yearLevel));
        if (preg_match('/\d+/', $label, $match) === 1) {
            return (int) $match[0];
        }

        foreach (['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5] as $word => $rank) {
            if (str_contains($label, $word)) {
                return $rank;
            }
        }

        return 99;
    }

    private static function termRank(?string $termLabel): int
    {
        $label = strtolower(trim((string) $termLabel));

        return match (true) {
            str_contains($label, 'first') || str_contains($label, '1st') => 1,
            str_contains($label, 'second') || str_contains($label, '2nd') => 2,
            str_contains($label, 'summer') || str_contains($label, 'special') || str_contains($label, 'mid') => 3,
            default => 9,
        };
    }
}
