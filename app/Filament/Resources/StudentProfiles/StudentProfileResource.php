<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Actions\Academics\AcademicEnrollmentGuidance;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\Pages\EditStudentProfile;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Filament\Resources\StudentProfiles\RelationManagers\HoldsRelationManager;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermCohort;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use UnitEnum;

class StudentProfileResource extends Resource
{
    protected static ?string $model = StudentProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Student Records';

    protected static ?string $navigationLabel = 'Student Profiles';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'student_number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Official Student Profile')->schema([
                TextInput::make('student_number')->disabled(),
                Select::make('entry_term_id')->label('Entry Term')->relationship('entryTerm', 'label')->disabled(),
                TextInput::make('first_name')->disabled(),
                TextInput::make('middle_name')->disabled(),
                TextInput::make('last_name')->disabled(),
                DatePicker::make('birth_date')->label('Date of Birth')->native(false)->displayFormat('M d, Y')->disabled(),
                TextInput::make('prior_identifier')->label('LRN / Prior-Education Identifier')->disabled(),
                Select::make('program_id')->relationship('program', 'name')->disabled(),
                Select::make('curriculum_version_id')->relationship('curriculumVersion', 'name')->disabled(),
                TextInput::make('email')->disabled(),
                TextInput::make('phone')->disabled(),
            ])->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $academicContext = ($schema->getLivewire()->viewTab ?? \Filament\Support\original_request()->query('viewTab')) === 'academic-progress';
        $sections = [
            'identity' => Section::make('Current Student Record')->collapsible($academicContext)->collapsed($academicContext)
                ->description($academicContext ? null : 'The institution-confirmed identity, program, curriculum, and lifecycle state.')
                ->schema([
                    TextEntry::make('student_number')->label('Student Number'),
                    TextEntry::make('full_name')->label('Student Name')->state(fn (StudentProfile $record): string => collect([$record->first_name, $record->middle_name, $record->last_name])->filter()->implode(' ')),
                    TextEntry::make('program.name')->label('Program'),
                    TextEntry::make('curriculumVersion.name')->label('Curriculum Version'),
                    TextEntry::make('lifecycle_status')
                        ->label('Student Lifecycle Status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => StudentProfile::lifecycleStatusLabel((string) $state)),
                ])
                ->columns(2)
                ->columnSpanFull(),
            'enrollment' => Section::make('Current Enrollment Context')->collapsible($academicContext)->collapsed($academicContext)
                ->description($academicContext ? null : 'Current active-term facts are derived from the Enrollment and its active subject records; they are not duplicate Student Profile fields.')
                ->schema([
                    RepeatableEntry::make('current_enrollment_context')
                        ->label('Active-Term Enrollment')
                        ->state(function (StudentProfile $record): array {
                            $context = app(EnrollmentAcademicContextResolver::class)->currentForProfile($record);

                            if ($context === null) {
                                return [];
                            }

                            return [[
                                'term' => $context['term_label'],
                                'status' => $context['enrollment_status_label'],
                                'type' => $context['enrollment_type_label'],
                                'curriculum_level' => $context['curriculum_level_label'],
                                'student_section' => $context['student_section_label'],
                                'course_delivery_mix' => $context['course_delivery_mix'],
                                'responsible_office' => $context['responsible_office'],
                            ]];
                        })
                        ->schema([
                            TextEntry::make('term')->label('Current Term')->weight('bold'),
                            TextEntry::make('status')->label('Enrollment Status')->badge(),
                            TextEntry::make('type')->label('Enrollment Type')->badge(),
                            TextEntry::make('curriculum_level')->label('Curriculum Level'),
                            TextEntry::make('student_section')->label('Student section'),
                            TextEntry::make('course_delivery_mix')->label('Course Delivery Mix'),
                            TextEntry::make('responsible_office')->label('Responsible Office'),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            'summary' => Section::make('Academic summary')
                ->description('Released academic results and guidance for the next enrollment.')
                ->schema([
                    SchemaView::make('filament.admin.student-profiles.academic-summary')
                        ->viewData(function (StudentProfile $record): array {
                            $results = app(OfficialCourseResultProjection::class)->forStudent($record);
                            $terms = $results->pluck('term')->filter()->unique('id')->sortByDesc('ends_on');

                            return [
                                'effect' => app(AcademicEnrollmentGuidance::class)->forStudent($record),
                                'curriculum' => app(CurriculumEvaluation::class)->forStudent($record),
                                'cumulative' => app(CumulativeGwaProjection::class)->forStudent($record),
                                'termAverages' => $terms->map(fn (Term $term): array => [
                                    'term' => $term,
                                    'average' => app(TermWeightedAverageProjection::class)->forResults($results, $term),
                                ]),
                            ];
                        })->columnSpanFull(),
                ])->columnSpanFull(),
            'holds' => Section::make('Active Holds and Next Steps')->collapsible($academicContext)->collapsed($academicContext)
                ->description('Only unresolved holds are shown here. The owning office is responsible for clearing each requirement.')
                ->schema([
                    RepeatableEntry::make('active_hold_rows')
                        ->label('Active Holds')
                        ->state(fn (StudentProfile $record): array => Hold::query()
                            ->where('student_profile_id', $record->getKey())
                            ->where('status', Hold::StatusActive)
                            ->latest('id')
                            ->get()
                            ->map(fn (Hold $hold): array => [
                                'type' => str($hold->hold_type)->headline()->toString(),
                                'effect' => str($hold->blocking_level)->headline()->toString(),
                                'reason' => $hold->reason,
                                'office' => $hold->studentFacingOfficeLabel(),
                                'next_step' => $hold->resolution_requirement ?: $hold->studentFacingMessage(),
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('type')->label('Hold')->badge(),
                            TextEntry::make('effect')->label('What It Blocks')->badge(),
                            TextEntry::make('office')->label('Responsible Office'),
                            TextEntry::make('reason')->label('Reason'),
                            TextEntry::make('next_step')->label('How to Resolve')->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            'enrollment-history' => Section::make($academicContext ? 'Past enrollments' : 'Enrollment History')->collapsible($academicContext)->collapsed($academicContext)
                ->description($academicContext ? null : 'Term-by-term Registration Case outcome, controlled selection basis, responsible office, and next action.')
                ->schema([
                    RepeatableEntry::make('enrollment_history_rows')
                        ->label('Enrollments')->hiddenLabel($academicContext)
                        ->state(fn (StudentProfile $record): array => Enrollment::query()
                            ->where('student_profile_id', $record->getKey())
                            ->when($academicContext && $schema->getLivewire() instanceof ViewStudentProfile && $schema->getLivewire()->academicProfile['enrollment'],
                                fn ($query) => $query->whereKeyNot($schema->getLivewire()->academicProfile['enrollment']->id))
                            ->with(['term', 'currentProposalVersion.confirmation', 'currentProposalVersion.items.reservation', 'termAccount'])
                            ->latest('id')
                            ->get()
                            ->map(fn (Enrollment $enrollment): array => [
                                'term' => $enrollment->term->label,
                                'status' => str((string) ($enrollment->canonical_outcome ?: $enrollment->status))->headline()->toString(),
                                'type' => str((string) ($enrollment->selection_basis ?: 'Legacy record'))->headline()->toString(),
                                'next_step' => self::registrationNextStep($enrollment),
                                'office' => self::registrationResponsibleOffice($enrollment),
                                'source_url' => EnrollmentResource::getUrl('view', ['record' => $enrollment]),
                                'schedule_url' => self::publishedScheduleUrl($enrollment),
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('term')->label('Academic Term')->weight('bold'),
                            TextEntry::make('status')->label('Enrollment Status')->badge(),
                            TextEntry::make('type')->label('Selection Basis')->badge(),
                            TextEntry::make('office')->label('Responsible Office'),
                            TextEntry::make('next_step')->label('Next Step')->columnSpanFull(),
                            TextEntry::make('source_label')
                                ->label('Enrollment Record')
                                ->state('Open Enrollment')
                                ->url(fn (Get $get): ?string => $get('source_url')),
                            TextEntry::make('schedule_label')
                                ->label('Published Schedule')
                                ->state('Open Published Schedule')
                                ->url(fn (Get $get): ?string => $get('schedule_url'))
                                ->visible(fn (Get $get): bool => filled($get('schedule_url'))),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            'results' => Section::make('Released grades')
                ->description('Released grades and authorized Registrar outcomes appear here. Open the owning roster for its audit context.')
                ->schema([
                    RepeatableEntry::make('released_grade_rows')
                        ->hiddenLabel()
                        ->placeholder('No released results are recorded yet.')
                        ->table([
                            TableColumn::make('Course'),
                            TableColumn::make('Term'),
                            TableColumn::make('Released result'),
                            TableColumn::make('Source'),
                        ])
                        ->state(fn (StudentProfile $record): array => app(OfficialCourseResultProjection::class)->forStudent($record)
                            ->filter(fn (array $result): bool => $result['outcome_source'] !== null)
                            ->map(function (array $result): array {
                                $event = $result['event'];
                                $row = $result['course_enrollment']->gradeRosterRow;
                                $specification = $result['course_specification'];

                                return [
                                    'term' => collect([$result['term']?->academicYear?->label, $result['term']?->label])->filter()->implode(' · '),
                                    'course' => collect([$specification?->course?->code, $specification?->title])->filter()->implode(' — '),
                                    'grade' => $result['result'],
                                    'category' => $event?->new_category ?? 'Registrar-recorded outcome',
                                    'released_on' => $event?->released_at?->format('M j, Y'),
                                    'inc_deadline' => $result['inc_deadline']?->format('M j, Y'),
                                    'inc_state' => $result['inc_state'] ? str($result['inc_state'])->headline()->toString() : null,
                                    'source_url' => $row !== null
                                        ? GradeRosterResource::getUrl('view', ['record' => $row->grade_roster_id, 'viewTab' => 'academic-progress'])
                                        : null,
                                ];
                            })->values()->all())
                        ->schema([
                            Group::make([
                                TextEntry::make('course')->hiddenLabel()->weight('semibold'),
                                TextEntry::make('category')->hiddenLabel()->color('gray'),
                            ])->gap(false),
                            Group::make([
                                TextEntry::make('term')->hiddenLabel(),
                                TextEntry::make('released_on')->hiddenLabel()->color('gray')
                                    ->formatStateUsing(fn (string $state): string => 'Released '.$state)
                                    ->placeholder('Registrar-recorded outcome'),
                            ])->gap(false),
                            Group::make([
                                TextEntry::make('grade')->hiddenLabel()->badge()->color('gray'),
                                TextEntry::make('inc_state')->hiddenLabel()->visible(fn (Get $get): bool => filled($get('inc_state'))),
                                TextEntry::make('inc_deadline')->hiddenLabel()
                                    ->formatStateUsing(fn (string $state): string => 'Until '.$state.' · Asia/Manila')
                                    ->visible(fn (Get $get): bool => filled($get('inc_deadline'))),
                            ])->gap(false),
                            TextEntry::make('source_url')
                                ->hiddenLabel()
                                ->formatStateUsing(fn (): string => 'Open Grade Roster')
                                ->url(fn (?string $state): ?string => $state)
                                ->placeholder('Registrar-recorded outcome'),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            'finance' => Section::make($academicContext ? 'Financial assessments' : 'Financial History')->collapsible($academicContext)->collapsed($academicContext)
                ->description($academicContext ? null : 'Assessment totals are shown by term. Payments and adjustments remain in their owning finance records.')
                ->schema([
                    RepeatableEntry::make('assessment_history_rows')
                        ->label('Assessments')->hiddenLabel($academicContext)
                        ->placeholder('No financial assessments recorded.')
                        ->state(fn (StudentProfile $record): array => Assessment::query()
                            ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $record->getKey()))
                            ->with('enrollment.term')
                            ->latest('id')
                            ->get()
                            ->map(fn (Assessment $assessment): array => [
                                'term' => $assessment->enrollment?->term?->label,
                                'version' => 'Version '.$assessment->version,
                                'status' => str($assessment->state)->headline()->toString(),
                                'total' => 'PHP '.number_format((float) $assessment->total, 2),
                                'source_url' => $assessment->enrollment !== null
                                    ? EnrollmentResource::getUrl('view', ['record' => $assessment->enrollment])
                                    : null,
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('term')->label('Academic Term')->weight('bold'),
                            TextEntry::make('version')->label('Assessment'),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('total')->label('Total Assessed'),
                            TextEntry::make('source_label')
                                ->label('Source Record')
                                ->state('Open Registration Case')
                                ->url(fn (Get $get): ?string => $get('source_url')),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            'lifecycle' => Section::make($academicContext ? 'Student status changes' : 'Lifecycle History')->collapsible($academicContext)->collapsed($academicContext)
                ->description($academicContext ? null : 'Approved student-status changes are retained chronologically for audit and explanation.')
                ->schema([
                    RepeatableEntry::make('lifecycle_history_rows')
                        ->label('Lifecycle Changes')->hiddenLabel($academicContext)
                        ->placeholder('No lifecycle changes recorded.')
                        ->state(fn (StudentProfile $record): array => StudentLifecycleChange::query()
                            ->where('student_profile_id', $record->getKey())
                            ->with('term')
                            ->latest('effective_on')
                            ->latest('id')
                            ->get()
                            ->map(fn (StudentLifecycleChange $change): array => [
                                'type' => str((string) $change->type)->headline()->toString(),
                                'term' => $change->term->label,
                                'effective_on' => $change->effective_on->format('M j, Y'),
                                'state' => str((string) $change->state)->headline()->toString(),
                                'reason' => $change->reason,
                                'source_url' => StudentLifecycleChangeResource::getUrl('index', ['change' => $change->id]),
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('type')->label('Change')->badge(),
                            TextEntry::make('term')->label('Academic Term'),
                            TextEntry::make('effective_on')->label('Effective Date'),
                            TextEntry::make('state')->label('Recorded State')->badge(),
                            TextEntry::make('reason')->label('Reason')->columnSpanFull(),
                            TextEntry::make('source_label')
                                ->label('Source Record')
                                ->state('Open Lifecycle Record')
                                ->url(fn (Get $get): ?string => $get('source_url')),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ];

        if ($academicContext && $schema->getLivewire() instanceof ViewStudentProfile) {
            $keys = match ($schema->getLivewire()->activeRecordTab()) {
                'academic' => ['results'],
                'enrollment' => $schema->getLivewire()->academicProfile['hasEnrollments'] ? ['enrollment-history'] : [],
                'history' => array_values(array_filter([
                    $schema->getLivewire()->academicProfile['hasAssessments'] ? 'finance' : null,
                    $schema->getLivewire()->academicProfile['hasLifecycleChanges'] ? 'lifecycle' : null,
                ])),
                default => [],
            };
            $sections = array_map(fn (string $key): Section => $sections[$key]->collapsible(false)->collapsed(false), $keys);
        }

        return $schema->components(array_values($sections));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'program',
                'curriculumVersion',
                'enrollments' => function (Relation $relation): void {
                    $relation->getQuery()
                        ->whereHas('term', fn (Builder $termQuery): Builder => $termQuery->where('state', Term::StateActive))
                        ->with([
                            'term',
                            'studentSection',
                            'studentProfile.program',
                            'studentProfile.curriculumVersion',
                            'courseEnrollments.termOffering.curriculumEntry',
                            'courseEnrollments.section.deliveryGroups',
                            'currentProposalVersion.items.section.deliveryGroups',
                        ]);
                },
            ]))
            ->columns([
                TextColumn::make('student_number')->searchable()->sortable(),
                TextColumn::make('last_name')->label('Student')->formatStateUsing(fn (StudentProfile $record): string => $record->last_name.', '.$record->first_name)->searchable(['last_name', 'first_name'])->sortable(),
                TextColumn::make('program.name')->label('Current program')->searchable()->sortable(),
                TextColumn::make('curriculumVersion.name')->label('Curriculum')->sortable(),
                TextColumn::make('current_term')
                    ->label('Current Term')
                    ->state(fn (StudentProfile $record): string => app(EnrollmentAcademicContextResolver::class)->currentForProfile($record)['term_label'] ?? 'No active-term enrollment')
                    ->wrap(),
                TextColumn::make('current_enrollment_status')
                    ->label('Enrollment Status')
                    ->state(fn (StudentProfile $record): string => app(EnrollmentAcademicContextResolver::class)->currentForProfile($record)['enrollment_status_label'] ?? 'Not started')
                    ->badge(),
                TextColumn::make('current_enrollment_type')
                    ->label('Enrollment Type')
                    ->state(fn (StudentProfile $record): string => app(EnrollmentAcademicContextResolver::class)->currentForProfile($record)['enrollment_type_label'] ?? 'Not recorded')
                    ->badge(),
                TextColumn::make('curriculum_level')
                    ->label('Curriculum Level')
                    ->state(fn (StudentProfile $record): string => app(EnrollmentAcademicContextResolver::class)->currentForProfile($record)['curriculum_level_label'] ?? 'Not recorded'),
                TextColumn::make('lifecycle_status')
                    ->label('Lifecycle Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => StudentProfile::lifecycleStatusLabel((string) $state))
                    ->sortable(),
                TextColumn::make('academic_effect')
                    ->label('Enrollment Guidance')
                    ->state(function (StudentProfile $record): string {
                        $guidance = app(AcademicEnrollmentGuidance::class)->forStudent($record);

                        return $guidance['label'].' · '.$guidance['scope_label'];
                    })
                    ->wrap()
                    ->badge(),
            ])->filters([
                ...self::academicBrowsingFilters(),
                SelectFilter::make('current_enrollment_status')
                    ->label('Current Enrollment Status')
                    ->options([
                        'not_started' => 'Not Started',
                        'pending_review' => 'Pending Review',
                        'capacity_pending' => 'Capacity Pending',
                        'pending_payment' => 'Payment Pending',
                        'ready_for_official_enrollment' => 'Ready for Official Enrollment',
                        'officially_enrolled' => 'Officially Enrolled',
                        'cancelled' => 'Cancelled',
                        'dropped' => 'Dropped',
                        'withdrawn' => 'Withdrawn',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $status): Builder => app(EnrollmentAcademicContextResolver::class)
                            ->applyCurrentEnrollmentStatusFilter($query, (string) $status),
                    )),
                SelectFilter::make('lifecycle_status')->options([
                    StudentProfile::LifecycleActive => 'Active',
                    StudentProfile::LifecycleLeaveOfAbsence => 'Leave of Absence',
                    StudentProfile::LifecycleWithdrawn => 'Withdrawn',
                    StudentProfile::LifecycleTransferredOut => 'Transferred Out',
                    StudentProfile::LifecycleInactive => 'Inactive',
                    StudentProfile::LifecycleArchived => 'Archived',
                ]),
            ])
            ->recordUrl(fn (StudentProfile $record, HasTable $livewire): string => self::academicRecordUrl($record, $livewire))
            ->recordActions([ViewAction::make()->url(fn (StudentProfile $record, HasTable $livewire): string => self::academicRecordUrl($record, $livewire)), EditAction::make()])
            ->defaultSort('student_number')
            ->stackedOnMobile();
    }

    public static function academicRecordUrl(StudentProfile $record, HasTable $livewire): string
    {
        return self::getUrl('view', ['record' => $record, ...(
            ($livewire->viewTab ?? \Filament\Support\original_request()->query('viewTab')) === 'academic-progress'
                ? ['viewTab' => 'academic-progress', 'queue' => self::academicQueueParameters([
                    'filters' => $livewire->tableFilters ?? [], 'search' => $livewire->getTableSearch(), 'page' => $livewire->getTablePage(),
                ])] : []
        )]);
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    public static function academicQueueParameters(array $parameters): array
    {
        $safe = [];
        foreach (['program' => ['value'], 'entry_year' => ['value'], 'enrollment_scope' => ['term_id', 'student_section_id']] as $filter => $fields) {
            foreach ($fields as $field) {
                $value = data_get($parameters, 'filters.'.$filter.'.'.$field);
                if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                    $safe['filters'][$filter][$field] = (int) $value;
                }
            }
        }
        if (is_string($parameters['search'] ?? null) && trim($parameters['search']) !== '') {
            $safe['search'] = mb_substr(trim($parameters['search']), 0, 200);
        }
        if (is_scalar($parameters['page'] ?? null) && ctype_digit((string) $parameters['page']) && (int) $parameters['page'] > 0) {
            $safe['page'] = (int) $parameters['page'];
        }

        return $safe;
    }

    public static function academicProgramLabel(Program $program): string
    {
        return collect([$program->code, $program->name])->filter()->implode(' · ');
    }

    /** @return list<Filter|SelectFilter> */
    public static function academicBrowsingFilters(): array
    {
        return [
            SelectFilter::make('program')->label('Current program')->relationship('program', 'name')->searchable(['name', 'code'])->preload()
                ->getOptionLabelFromRecordUsing(fn (Program $record): string => self::academicProgramLabel($record)),
            Filter::make('enrollment_scope')->label('Enrollment')
                ->schema([
                    Select::make('term_id')->label('Enrolled term')->placeholder('All enrolled terms')->searchable()
                        ->options(fn (): array => Term::query()->with('academicYear')->orderByDesc('starts_on')->get()
                            ->mapWithKeys(fn (Term $term): array => [$term->id => collect([$term->academicYear?->label, $term->label])->filter()->implode(' · ')])->all())
                        ->live()->afterStateUpdated(fn (Set $set) => $set('student_section_id', null)),
                    Select::make('student_section_id')->label('Recorded student section')->placeholder('All recorded sections')->searchable()
                        ->options(fn (Get $get): array => self::studentSectionFilterOptions($get('term_id')))
                        ->getSearchResultsUsing(fn (Get $get, string $search): array => self::studentSectionFilterOptions($get('term_id'), $search))
                        ->getOptionLabelUsing(function (mixed $value): ?string {
                            $section = TermCohort::query()->with(['term.academicYear', 'program'])->find($value);

                            return $section instanceof TermCohort ? self::studentSectionFilterLabel($section) : null;
                        }),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    filled($data['term_id'] ?? null) || filled($data['student_section_id'] ?? null),
                    fn (Builder $profiles): Builder => $profiles->whereHas('enrollments', fn (Builder $enrollments): Builder => $enrollments
                        ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                        ->when($data['term_id'] ?? null, fn (Builder $scope, mixed $termId): Builder => $scope->where('term_id', $termId))
                        ->when($data['student_section_id'] ?? null, fn (Builder $scope, mixed $sectionId): Builder => $scope->where('student_section_id', $sectionId))),
                ))
                ->indicateUsing(function (array $data): array {
                    $indicators = [];
                    $term = filled($data['term_id'] ?? null) ? Term::query()->with('academicYear')->find($data['term_id']) : null;
                    if ($term instanceof Term) {
                        $indicators[] = 'Enrolled term: '.collect([$term->academicYear?->label, $term->label])->filter()->implode(' · ');
                    }
                    $section = filled($data['student_section_id'] ?? null) ? TermCohort::query()->find($data['student_section_id']) : null;
                    if ($section instanceof TermCohort) {
                        $indicators[] = 'Student section: '.$section->reference;
                    }

                    return $indicators;
                }),
            SelectFilter::make('entry_year')->label('Entry academic year')
                ->options(fn (): array => Term::query()->with('academicYear')->whereIn('id', StudentProfile::query()->select('entry_term_id'))->get()
                    ->filter(fn (Term $term): bool => $term->academicYear !== null)->mapWithKeys(fn (Term $term): array => [$term->academic_year_id => $term->academicYear->label])->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                    fn (Builder $scope, mixed $yearId): Builder => $scope->whereHas('entryTerm', fn (Builder $terms): Builder => $terms->where('academic_year_id', $yearId)))),
        ];
    }

    /** @return array<int, string> */
    private static function studentSectionFilterOptions(?int $termId, string $search = ''): array
    {
        return TermCohort::query()->with(['term.academicYear', 'program'])
            ->when($termId, fn (Builder $query): Builder => $query->where('term_id', $termId))
            ->when($search !== '', fn (Builder $query): Builder => $query->where('reference', 'like', '%'.$search.'%'))
            ->orderBy('reference')->limit(50)->get()
            ->mapWithKeys(fn (TermCohort $section): array => [$section->id => self::studentSectionFilterLabel($section)])->all();
    }

    private static function studentSectionFilterLabel(TermCohort $section): string
    {
        return collect([$section->reference, $section->program?->name, $section->term?->academicYear?->label, $section->term?->label])->filter()->implode(' · ');
    }

    public static function getRelations(): array
    {
        return [ChecklistItemsRelationManager::class, HoldsRelationManager::class];
    }

    private static function publishedScheduleUrl(Enrollment $enrollment): ?string
    {
        if (! $enrollment->courseEnrollments()
            ->where('status', 'active')
            ->where('is_current', true)
            ->whereNotNull('section_id')
            ->exists()) {
            return null;
        }

        $version = PublishedTimetableVersion::query()
            ->where('term_id', $enrollment->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')
            ->first();

        return $version?->schedule_run_id !== null
            ? ScheduleGenerationRunResource::getUrl('view', ['record' => $version->schedule_run_id])
            : null;
    }

    private static function registrationNextStep(Enrollment $enrollment): string
    {
        if ($enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return 'Official registrations, schedule, and immutable COR are available.';
        }

        if (in_array($enrollment->canonical_outcome, Enrollment::reopenableOutcomes(), true)) {
            return 'Registrar reviews the preserved case history and any authorized same-case recovery.';
        }

        $proposal = $enrollment->currentProposalVersion;
        if (! $proposal instanceof RegistrationProposalVersion || $proposal->state === RegistrationProposalVersion::StateDraft) {
            return 'Registrar prepares and issues the complete exact-Term proposal.';
        }

        if ($proposal->state === RegistrationProposalVersion::StateIssued) {
            return 'Learner reviews and confirms this exact proposal version.';
        }

        $placed = $proposal->items->isNotEmpty()
            && $proposal->items->every(fn ($item): bool => in_array($item->reservation?->status, ['active', 'converted'], true));
        if (! $placed) {
            return 'Registrar resolves capacity, conflicts, and protected placement.';
        }

        if ($enrollment->termAccount?->state !== TermAccount::StateCleared) {
            return 'Accounting resolves the current assessment requirement.';
        }

        return 'Registrar revalidates all five checkpoints and finalizes official enrollment.';
    }

    private static function registrationResponsibleOffice(Enrollment $enrollment): string
    {
        $nextStep = self::registrationNextStep($enrollment);

        return str_starts_with($nextStep, 'Learner')
            ? 'Learner'
            : (str_starts_with($nextStep, 'Accounting') ? 'Accounting Office' : 'Registrar Office');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentProfiles::route('/'),
            'view' => ViewStudentProfile::route('/{record}'),
            'edit' => EditStudentProfile::route('/{record}/edit'),
        ];
    }
}
