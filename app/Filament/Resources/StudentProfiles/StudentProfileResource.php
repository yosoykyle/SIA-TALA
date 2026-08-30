<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Actions\StudentHub\StudentGradeLabelFormatter;
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
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
        return $schema->components([
            Section::make('Current Student Record')
                ->description('The institution-confirmed identity, program, curriculum, and lifecycle state.')
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
            Section::make('Current Enrollment Context')
                ->description('Current active-term facts are derived from the Enrollment and its active subject records; they are not duplicate Student Profile fields.')
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
                                'course_delivery_mix' => $context['course_delivery_mix'],
                                'responsible_office' => $context['responsible_office'],
                            ]];
                        })
                        ->schema([
                            TextEntry::make('term')->label('Current Term')->weight('bold'),
                            TextEntry::make('status')->label('Enrollment Status')->badge(),
                            TextEntry::make('type')->label('Enrollment Type')->badge(),
                            TextEntry::make('curriculum_level')->label('Curriculum Level'),
                            TextEntry::make('course_delivery_mix')->label('Course Delivery Mix'),
                            TextEntry::make('responsible_office')->label('Responsible Office'),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Academic Record Projection')
                ->description('Released results drive factual curriculum and enrollment guidance. Consequential decisions require separately recorded authority.')
                ->schema([
                    RepeatableEntry::make('academic_record_summary')
                        ->label('Current Academic Projection')
                        ->state(function (StudentProfile $record): array {
                            $effect = app(AcademicEnrollmentEffect::class)->forStudent($record);
                            $curriculum = app(CurriculumEvaluation::class)->forStudent($record);
                            $cumulative = app(CumulativeGwaProjection::class)->forStudent($record);

                            return [[
                                'effect' => str($effect['effect'])->headline()->toString(),
                                'effect_reason' => $effect['reason'],
                                'effect_source' => $effect['source'],
                                'cumulative_gwa' => $cumulative['value'] ?? 'Not available',
                                'through' => $cumulative['through'] ?? 'No complete released term',
                                'completed_units' => $curriculum['completed_units'],
                                'remaining_requirements' => $curriculum['deficiency_count'],
                            ]];
                        })
                        ->schema([
                            TextEntry::make('effect')
                                ->label('Enrollment Guidance')
                                ->badge(),
                            TextEntry::make('effect_reason')
                                ->label('Explanation')
                                ->columnSpanFull(),
                            TextEntry::make('effect_source')->label('Authority / Source'),
                            TextEntry::make('cumulative_gwa')->label('Cumulative GWA'),
                            TextEntry::make('through')->label('Calculated Through'),
                            TextEntry::make('completed_units')->label('Completed Curriculum Units'),
                            TextEntry::make('remaining_requirements')->label('Remaining Requirements'),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Active Holds and Next Steps')
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
            Section::make('Enrollment History')
                ->description('Term-by-term Registration Case outcome, controlled selection basis, responsible office, and next action.')
                ->schema([
                    RepeatableEntry::make('enrollment_history_rows')
                        ->label('Enrollments')
                        ->state(fn (StudentProfile $record): array => Enrollment::query()
                            ->where('student_profile_id', $record->getKey())
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
            Section::make('Released Academic History')
                ->description('Only grades released through an official roster appear here. Open the owning roster for its complete audit context.')
                ->schema([
                    RepeatableEntry::make('released_grade_rows')
                        ->label('Released Grades')
                        ->state(fn (StudentProfile $record): array => GradeRosterRow::query()
                            ->whereNotNull('released_at')
                            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('student_profile_id', $record->getKey()))
                            ->with([
                                'roster',
                                'courseEnrollment.enrollment.term',
                                'courseEnrollment.termOffering.curriculumEntry.courseSpecification.course',
                            ])
                            ->latest('released_at')
                            ->get()
                            ->map(function (GradeRosterRow $row): array {
                                $specification = $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification;

                                return [
                                    'term' => $row->courseEnrollment?->enrollment?->term?->label,
                                    'course' => collect([$specification?->course?->code, $specification?->title])->filter()->implode(' — '),
                                    'grade' => app(StudentGradeLabelFormatter::class)->displayGrade($row->current_outcome_code, $row->current_outcome_category),
                                    'category' => $row->current_outcome_category,
                                    'released_on' => $row->released_at?->format('M j, Y'),
                                    'source_url' => $row->roster !== null
                                        ? GradeRosterResource::getUrl('view', ['record' => $row->roster])
                                        : null,
                                ];
                            })
                            ->all())
                        ->schema([
                            TextEntry::make('term')->label('Academic Term')->weight('bold'),
                            TextEntry::make('course')->label('Subject'),
                            TextEntry::make('grade')->label('Released Grade')->badge(),
                            TextEntry::make('category')->label('Outcome')->badge(),
                            TextEntry::make('released_on')->label('Released On'),
                            TextEntry::make('source_label')
                                ->label('Source Record')
                                ->state('Open Grade Roster')
                                ->url(fn (Get $get): ?string => $get('source_url')),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Financial History')
                ->description('Assessment totals are shown by term. Payments and adjustments remain in their owning finance records.')
                ->schema([
                    RepeatableEntry::make('assessment_history_rows')
                        ->label('Assessments')
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
            Section::make('Lifecycle History')
                ->description('Approved student-status changes are retained chronologically for audit and explanation.')
                ->schema([
                    RepeatableEntry::make('lifecycle_history_rows')
                        ->label('Lifecycle Changes')
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
                                'source_url' => StudentLifecycleChangeResource::getUrl('view', ['record' => $change]),
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
        ]);
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
                TextColumn::make('program.name')->searchable()->sortable(),
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
                    ->state(fn (StudentProfile $record): string => str(app(AcademicEnrollmentEffect::class)->forStudent($record)['effect'])->headline()->toString())
                    ->badge(),
            ])->filters([
                SelectFilter::make('program')
                    ->relationship('program', 'name')
                    ->searchable()
                    ->preload(),
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
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('student_number')
            ->stackedOnMobile();
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
