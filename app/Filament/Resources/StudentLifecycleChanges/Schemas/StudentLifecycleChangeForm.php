<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Schemas;

use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Resources\StudentLifecycleChanges\Pages\CreateStudentLifecycleChange;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;

class StudentLifecycleChangeForm
{
    public static function configure(Schema $schema): Schema
    {
        $invalidate = fn (Set $set) => $set('impact_confirmed', false);

        return $schema->components([
            Group::make([
                SchemaView::make('filament.admin.student-lifecycle.student-selection'),
                Select::make('student_profile_id')->label('Student')
                    ->relationship('studentProfile', 'student_number', modifyQueryUsing: fn ($query) => $query->with('program'))
                    ->getOptionLabelFromRecordUsing(fn ($record): string => collect([
                        $record->last_name.', '.$record->first_name,
                        $record->student_number,
                        $record->program?->name,
                    ])->filter()->implode(' · '))
                    ->placeholder('Search name or student number')
                    ->searchPrompt('Enter a student name or number')
                    ->noSearchResultsMessage('No matching student. Try another name or student number.')
                    ->searchable(['student_number', 'last_name', 'first_name'])->preload()->live()
                    ->visible(fn (Get $get): bool => blank($get('student_profile_id')))
                    ->dehydratedWhenHidden()->required()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('enrollment_id', null);
                        $set('impact_confirmed', false);
                    }),
            ])->extraAttributes(['class' => 'tala-record-picker servitech-journey-scene'])
                ->visible(fn (Get $get): bool => blank($get('student_profile_id')))
                ->dehydratedWhenHidden()->columnSpanFull(),
            SchemaView::make('filament.admin.student-lifecycle.identity')
                ->viewData(fn (Get $get): array => ['student' => StudentProfile::query()->with(['program', 'user'])->find($get('student_profile_id'))])
                ->visible(fn (Get $get): bool => filled($get('student_profile_id')))
                ->columnSpanFull(),
            Group::make([
                Grid::make(1)->schema([
                    Section::make()
                        ->schema([
                            SchemaView::make('filament.admin.student-lifecycle.form-heading')
                                ->viewData(['type' => null])->columnSpanFull(),
                            Radio::make('type')->label('What changed?')->hiddenLabel()
                                ->options(self::changeOptions())
                                ->descriptions(fn (Get $get): array => [
                                    StudentLifecycleChange::TypeLeaveOfAbsence => 'Approved temporary leave',
                                    StudentLifecycleChange::TypeWithdrawal => 'End this term’s enrollment',
                                    StudentLifecycleChange::TypeTransferOut => 'Move to another school',
                                    StudentLifecycleChange::TypeReactivation => self::canReturnToStudies($get('student_profile_id')) ? 'Resume after leave or inactivity' : 'Student is already active or not eligible',
                                    StudentLifecycleChange::TypeProgramShift => 'Move to another program',
                                    StudentLifecycleChange::TypeInactivation => 'Registrar-confirmed absence',
                                ])
                                ->disableOptionWhen(fn (string $value, Get $get): bool => $value === StudentLifecycleChange::TypeReactivation && ! self::canReturnToStudies($get('student_profile_id')))
                                ->extraAttributes(['class' => 'tala-choice-options'])->columnSpanFull()
                                ->required()->live()->afterStateUpdated(function (Set $set): void {
                                    foreach (['expected_return_term_id', 'target_program_id', 'target_curriculum_version_id', 'late_exception_authority', 'late_exception_reason'] as $field) {
                                        $set($field, null);
                                    }
                                    $set('credit_entries', []);
                                    $set('impact_confirmed', false);
                                }),
                            SchemaView::make('filament.admin.student-lifecycle.form-heading')
                                ->viewData(fn (Get $get): array => ['type' => $get('type')])
                                ->visible(fn (Get $get): bool => filled($get('type')))->columnSpanFull(),
                            Select::make('term_id')->label('Term')->options(fn (): array => self::termOptions())
                                ->searchable()->preload()->required()->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('enrollment_id', null);
                                    $set('impact_confirmed', false);
                                }),
                            DatePicker::make('effective_on')->label('Effective date')->default(today('Asia/Manila'))
                                ->required()->live()->afterStateUpdated($invalidate),
                            Select::make('expected_return_term_id')->label('Expected return term')->options(fn (): array => self::termOptions())->searchable()->preload()
                                ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeLeaveOfAbsence)
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                                ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeLeaveOfAbsence),
                            Textarea::make('reason')->label('Reason for change')->rows(2)->maxLength(2000)
                                ->required()->live(onBlur: true)->afterStateUpdated($invalidate)
                                ->columnSpan(fn (Get $get): int|string => $get('type') === StudentLifecycleChange::TypeLeaveOfAbsence ? 1 : 'full'),
                            Group::make()
                                ->visible(fn (Get $get): bool => in_array($get('type'), [StudentLifecycleChange::TypeProgramShift], true))
                                ->schema([
                                    Select::make('target_program_id')->relationship('targetProgram', 'name')->searchable()->preload()
                                        ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                                        ->live()
                                        ->afterStateUpdated(function (Set $set): void {
                                            $set('target_curriculum_version_id', null);
                                            $set('credit_entries', []);
                                            $set('impact_confirmed', false);
                                        })
                                        ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift),
                                    Select::make('target_curriculum_version_id')
                                        ->label('Target curriculum')
                                        ->options(fn (Get $get): array => CurriculumVersion::query()
                                            ->when($get('target_program_id'), fn ($query, $programId) => $query->where('program_id', $programId))
                                            ->orderBy('name')
                                            ->get()
                                            ->mapWithKeys(fn (CurriculumVersion $version): array => [
                                                $version->id => $version->name.' ('.$version->version_code.')',
                                            ])->all())
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set): void {
                                            $set('credit_entries', []);
                                            $set('impact_confirmed', false);
                                        })
                                        ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                                        ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift),
                                    Repeater::make('credit_entries')
                                        ->label('Evaluated subjects')
                                        ->helperText('Add only evaluated subjects. Leave empty when no subjects are credited; target requirements remain to be completed.')
                                        ->addActionLabel('Add evaluated subject')
                                        ->defaultItems(0)
                                        ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                                        ->schema([
                                            Select::make('curriculum_entry_id')
                                                ->label('Target course')
                                                ->options(fn (Get $get): array => blank($get('../../target_curriculum_version_id')) ? [] : CurriculumEntry::query()
                                                    ->where('curriculum_version_id', $get('../../target_curriculum_version_id'))
                                                    ->with('courseSpecification.course')
                                                    ->get()
                                                    ->mapWithKeys(fn (CurriculumEntry $entry): array => [
                                                        $entry->id => $entry->courseSpecification->course->code.' - '.$entry->courseSpecification->title,
                                                    ])->all())
                                                ->searchable()
                                                ->required()
                                                ->distinct()
                                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                            Select::make('treatment')->options([
                                                ProgramShiftCreditEntry::TreatmentAccepted => 'Accepted',
                                                ProgramShiftCreditEntry::TreatmentDeficient => 'Deficient',
                                                ProgramShiftCreditEntry::TreatmentRejected => 'Rejected',
                                            ])->required()->live()
                                                ->afterStateUpdated(fn (Set $set) => $set('source_grade_outcome_event_id', null)),
                                            Select::make('source_grade_outcome_event_id')
                                                ->label('Released result to credit')
                                                ->options(function (Get $get): array {
                                                    $student = StudentProfile::query()->find($get('../../student_profile_id'));

                                                    return $student === null ? [] : app(StudentLifecycleService::class)->programShiftSources($student)
                                                        ->mapWithKeys(fn (array $result): array => [$result['event']->id => collect([
                                                            $result['course_specification']?->course?->code,
                                                            $result['course_specification']?->title,
                                                            $result['result'], $result['term']?->label,
                                                            $result['term']?->academicYear?->label,
                                                        ])->filter()->implode(' · ')])->all();
                                                })
                                                ->helperText('The source course and grade are recorded automatically from this student’s released result.')
                                                ->visible(fn (Get $get): bool => $get('treatment') === ProgramShiftCreditEntry::TreatmentAccepted)
                                                ->searchable()->columnSpanFull()
                                                ->required(fn (Get $get): bool => $get('treatment') === ProgramShiftCreditEntry::TreatmentAccepted),
                                        ])->columns(2)->columnSpanFull(),
                                ])->columns(2)->columnSpanFull(),

                            Group::make([
                                TextInput::make('late_exception_authority')->label('Late exception authority')->maxLength(255)
                                    ->live(onBlur: true)->afterStateUpdated($invalidate),
                                Textarea::make('late_exception_reason')->label('Reason for the late exception')->maxLength(2000)->rows(2)
                                    ->live(onBlur: true)->afterStateUpdated($invalidate),
                            ])->visible(fn (Get $get): bool => filled($get('type')) && filled($get('term_id')) && ! self::withinWindow($get))
                                ->extraAttributes(['class' => 'tala-lifecycle-exception'])->columnSpanFull(),
                        ])->columns(['default' => 1, 'sm' => 2])->extraAttributes(['class' => 'tala-lifecycle-form']),
                    Group::make([
                        SchemaView::make('filament.admin.student-lifecycle.effects')
                            ->viewData(fn (Get $get): array => [
                                'state' => self::previewState($get),
                                'student' => StudentProfile::query()->find($get('student_profile_id')),
                                'hasTerm' => filled($get('term_id')),
                                'enrollment' => self::selectedEnrollment($get),
                            ]),
                        SchemaView::make('filament.admin.student-lifecycle.handoff')
                            ->viewData(fn (Get $get): array => ['state' => self::previewState($get)]),
                    ])->extraAttributes(['class' => 'tala-lifecycle-review-column']),
                ])->extraAttributes(['class' => 'tala-lifecycle-columns'])->columnSpanFull(),
                Group::make([
                    Actions::make(fn (CreateStudentLifecycleChange $livewire): array => [$livewire->recordingActions()[0]])
                        ->extraAttributes(['class' => 'tala-lifecycle-cancel']),
                    Checkbox::make('impact_confirmed')->label('I reviewed these effects.')
                        ->helperText('Recorder and time are saved automatically.')
                        ->live()->accepted()->disabled(fn (Get $get): bool => ! self::previewState($get)['valid'])->dehydrated(false),
                    Actions::make(fn (CreateStudentLifecycleChange $livewire): array => [$livewire->recordingActions()[1]])
                        ->extraAttributes(['class' => 'tala-lifecycle-submit']),
                ])->extraAttributes(['class' => 'tala-lifecycle-confirmation'])->columnSpanFull(),
            ])->extraAttributes(['class' => 'tala-lifecycle-workarea'])
                ->visible(fn (Get $get): bool => filled($get('student_profile_id')))
                ->dehydratedWhenHidden()->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    public static function changeOptions(): array
    {
        return [
            StudentLifecycleChange::TypeLeaveOfAbsence => 'Leave of absence',
            StudentLifecycleChange::TypeWithdrawal => 'Withdraw from term',
            StudentLifecycleChange::TypeTransferOut => 'Transfer out',
            StudentLifecycleChange::TypeReactivation => 'Return to studies',
            StudentLifecycleChange::TypeProgramShift => 'Change program',
            StudentLifecycleChange::TypeInactivation => 'Record inactivity',
        ];
    }

    private static function canReturnToStudies(mixed $studentId): bool
    {
        return in_array(StudentProfile::query()->find($studentId)?->lifecycle_status, [
            StudentProfile::LifecycleArchived, StudentProfile::LifecycleInactive,
            StudentProfile::LifecycleLeaveOfAbsence, StudentProfile::LifecycleWithdrawn,
        ], true);
    }

    /** @return array<int, string> */
    private static function termOptions(): array
    {
        return Term::query()->with('academicYear')->orderByDesc('starts_on')->get()
            ->mapWithKeys(fn (Term $term): array => [$term->id => $term->label.' · '.($term->academicYear?->label ?? 'Academic year not recorded')])->all();
    }

    private static function selectedEnrollment(Get $get): ?Enrollment
    {
        $student = StudentProfile::query()->find($get('student_profile_id'));
        $term = Term::query()->find($get('term_id'));
        if (! $student || ! $term) {
            return null;
        }
        try {
            return app(StudentLifecycleService::class)->resolveEnrollment($student, $term, $get('enrollment_id'));
        } catch (StudentLifecycleRuleViolation) {
            return null;
        }
    }

    private static function withinWindow(Get $get): bool
    {
        $term = Term::query()->find($get('term_id'));

        return $term instanceof Term && app(StudentLifecycleService::class)->isWindowOpen($term, (string) $get('type'));
    }

    /** @return array<string, mixed> */
    private static function lifecycleData(Get $get): array
    {
        return [
            'student_profile_id' => $get('student_profile_id'),
            'term_id' => $get('term_id'),
            'type' => $get('type'),
            'enrollment_id' => $get('enrollment_id'),
            'requested_on' => $get('requested_on'),
            'effective_on' => $get('effective_on'),
            'decided_on' => $get('decided_on'),
            'authority' => $get('authority'),
            'reason' => $get('reason'),
            'expected_return_term_id' => $get('expected_return_term_id'),
            'target_program_id' => $get('target_program_id'),
            'target_curriculum_version_id' => $get('target_curriculum_version_id'),
            'late_exception_authority' => $get('late_exception_authority'),
            'late_exception_reason' => $get('late_exception_reason'),
            'credit_entries' => $get('credit_entries') ?? [],
        ];
    }

    /**
     * @return array{valid: bool, preview: array<string, mixed>, message: string}
     */
    private static function previewState(Get $get): array
    {
        return self::previewFor(self::lifecycleData($get));
    }

    /** @param array<string, mixed> $data
     * @return array{valid: bool, preview: array<string, mixed>, message: string}
     */
    public static function previewFor(array $data): array
    {

        if (blank($data['student_profile_id'] ?? null) || blank($data['term_id'] ?? null) || blank($data['type'] ?? null) || blank($data['effective_on'] ?? null)) {
            return [
                'valid' => false,
                'preview' => [],
                'message' => 'Complete the student, term, change type, and effective date to preview the operational impact.',
            ];
        }

        try {
            return [
                'valid' => true,
                'preview' => app(StudentLifecycleService::class)->preview($data),
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
}
