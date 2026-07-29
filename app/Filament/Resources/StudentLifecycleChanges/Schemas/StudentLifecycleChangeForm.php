<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Schemas;

use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class StudentLifecycleChangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('1. Approved Lifecycle Result')
                    ->description('Record an approval already issued by the institution. TALA does not replace the institution’s approval process.')
                    ->schema([
                        Select::make('student_profile_id')
                            ->label('Student')
                            ->relationship('studentProfile', 'student_number')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->student_number.' — '.$record->last_name.', '.$record->first_name)
                            ->searchable(['student_number', 'last_name', 'first_name'])
                            ->preload()
                            ->helperText('Select the student whose approved status change is being recorded.')
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('enrollment_id', null);
                                $set('course_enrollment_id', null);
                                $set('impact_confirmed', false);
                            })
                            ->required(),
                        Select::make('term_id')
                            ->relationship('term', 'label')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                            ->required(),
                        Select::make('type')
                            ->options(StudentLifecycleChange::typeOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                        Select::make('enrollment_id')
                            ->label('Affected enrollment')
                            ->options(fn (Get $get): array => Enrollment::query()
                                ->when(
                                    filled($get('student_profile_id')),
                                    fn ($query) => $query->where('student_profile_id', $get('student_profile_id')),
                                    fn ($query) => $query->whereRaw('1 = 0'),
                                )
                                ->with('term')
                                ->latest('id')
                                ->get()
                                ->mapWithKeys(fn (Enrollment $record): array => [$record->id => $record->displayLabel()])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('course_enrollment_id', null);
                                $set('impact_confirmed', false);
                            })
                            ->helperText('Required when the action changes an existing term enrollment.'),
                        Select::make('course_enrollment_id')
                            ->label('Subject to drop')
                            ->options(fn (Get $get): array => CourseEnrollment::query()
                                ->when(
                                    filled($get('enrollment_id')),
                                    fn ($query) => $query->where('enrollment_id', $get('enrollment_id')),
                                    fn ($query) => $query->whereRaw('1 = 0'),
                                )
                                ->with('termOffering.curriculumEntry.courseSpecification.course')
                                ->get()
                                ->mapWithKeys(fn (CourseEnrollment $record): array => [$record->id => self::courseEnrollmentLabel($record)])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeSubjectDrop)
                            ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeSubjectDrop),
                        DatePicker::make('requested_on')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                        DatePicker::make('effective_on')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                        DatePicker::make('decided_on')
                            ->required()
                            ->default(today())
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                        TextInput::make('authority')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                        TextInput::make('private_source_reference')->maxLength(255),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(2000)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                            ->columnSpanFull(),
                    ])->columns(2),
                Section::make('Type-specific Details')
                    ->schema([
                        Select::make('expected_return_term_id')->relationship('expectedReturnTerm', 'label')->searchable()->preload()
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeLeaveOfAbsence)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                            ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeLeaveOfAbsence),
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
                        TextInput::make('finance_adjustment')
                            ->label('Finance adjustment (PHP)')
                            ->helperText('Use 0 when the approved action has no financial adjustment.')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                        Repeater::make('credit_entries')
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                            ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                            ->minItems(1)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                            ->schema([
                                Select::make('curriculum_entry_id')
                                    ->options(fn (Get $get): array => CurriculumEntry::query()
                                        ->when($get('../../target_curriculum_version_id'), fn ($query, $curriculumVersionId) => $query->where('curriculum_version_id', $curriculumVersionId))
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
                                ])->required()->live(),
                                Select::make('source_course_id')
                                    ->options(fn (): array => Course::query()->orderBy('code')->pluck('code', 'id')->all())
                                    ->searchable()
                                    ->required(fn (Get $get): bool => $get('treatment') === ProgramShiftCreditEntry::TreatmentAccepted),
                                TextInput::make('numeric_grade')
                                    ->numeric()
                                    ->minValue(1.00)
                                    ->maxValue(5.00)
                                    ->step(0.01)
                                    ->required(fn (Get $get): bool => $get('treatment') === ProgramShiftCreditEntry::TreatmentAccepted),
                            ])->columns(2)->columnSpanFull(),
                    ])->columns(2),
                Section::make('2. Review Operational Impact')
                    ->description('This preview is read-only. No student, enrollment, schedule, seat, finance, or lifecycle record is changed until you confirm below.')
                    ->schema([
                        Placeholder::make('impact_preview')
                            ->label('What will change')
                            ->content(fn (Get $get): HtmlString => self::impactSummary($get))
                            ->columnSpanFull(),
                        Checkbox::make('impact_confirmed')
                            ->label('I reviewed this impact and confirm that the approved result should be recorded.')
                            ->helperText('Checking this confirms only the system record and effects shown above. It does not represent a new institutional approval.')
                            ->accepted()
                            ->disabled(fn (Get $get): bool => ! self::previewState($get)['valid'])
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Late Exception')
                    ->schema([
                        TextInput::make('late_exception_authority')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                        Textarea::make('late_exception_reason')
                            ->maxLength(2000)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                    ])->columns(2)->collapsed(),
            ]);
    }

    private static function impactSummary(Get $get): HtmlString
    {
        $state = self::previewState($get);

        if (! $state['valid']) {
            return new HtmlString('<p><strong>Impact preview unavailable:</strong> '.e($state['message']).'</p>');
        }

        $preview = $state['preview'];
        $subjects = collect($preview['affected_subjects'] ?? [])
            ->map(fn (array $subject): string => collect([$subject['code'] ?? null, $subject['title'] ?? null])->filter()->implode(' — '))
            ->filter()
            ->implode(', ');
        $subjectCount = count((array) ($preview['course_enrollment_ids'] ?? []));
        $bindingCount = count((array) ($preview['binding_ids'] ?? []));
        $reservationCount = count((array) ($preview['reservation_ids'] ?? []));
        $statusAfter = str((string) ($preview['profile_status_after'] ?? 'unchanged'))->headline()->toString();
        $corAvailability = (bool) ($preview['cor_available_after'] ?? false) ? 'Available' : 'Unavailable';
        $programBefore = (string) data_get($preview, 'program_before.name', 'Not recorded');
        $programAfter = (string) data_get($preview, 'program_after.name', $programBefore);
        $curriculumBefore = (string) data_get($preview, 'curriculum_version_before.name', 'Not recorded');
        $curriculumAfter = (string) data_get($preview, 'curriculum_version_after.name', $curriculumBefore);
        $holdCount = (int) ($preview['active_hold_count'] ?? 0);
        $holdSummary = collect($preview['active_holds'] ?? [])
            ->map(fn (array $hold): string => collect([$hold['type'] ?? null, $hold['office'] ?? null])->filter()->implode(' — '))
            ->implode(', ');
        $financeEffect = (string) data_get($preview, 'finance_effect.message', 'No automatic finance effect is recorded.');

        return new HtmlString(
            '<div class="space-y-2">'
            .'<p><strong>Affected subjects:</strong> '.e("{$subjectCount} subject enrollment(s)").($subjects !== '' ? ' — '.e($subjects) : '').'</p>'
            .'<p><strong>Student schedule:</strong> '.e("{$bindingCount} active assignment(s) will be released").'</p>'
            .'<p><strong>Reserved seats:</strong> '.e("{$reservationCount} active reservation(s) will be released").'</p>'
            .'<p><strong>Student status after action:</strong> '.e($statusAfter).'</p>'
            .'<p><strong>Program:</strong> '.e($programBefore.' → '.$programAfter).'</p>'
            .'<p><strong>Curriculum:</strong> '.e($curriculumBefore.' → '.$curriculumAfter).'</p>'
            .'<p><strong>Active holds:</strong> '.e("{$holdCount} remain unchanged").($holdSummary !== '' ? ' — '.e($holdSummary) : '').'</p>'
            .'<p><strong>Finance:</strong> '.e($financeEffect).'</p>'
            .'<p><strong>COR after action:</strong> '.e($corAvailability).'</p>'
            .'<p><strong>Master schedule:</strong> Published master schedule stays unchanged</p>'
            .'</div>',
        );
    }

    /** @return array<string, mixed> */
    private static function lifecycleData(Get $get): array
    {
        return [
            'student_profile_id' => $get('student_profile_id'),
            'term_id' => $get('term_id'),
            'type' => $get('type'),
            'enrollment_id' => $get('enrollment_id'),
            'course_enrollment_id' => $get('course_enrollment_id'),
            'requested_on' => $get('requested_on'),
            'effective_on' => $get('effective_on'),
            'decided_on' => $get('decided_on'),
            'authority' => $get('authority'),
            'reason' => $get('reason'),
            'expected_return_term_id' => $get('expected_return_term_id'),
            'target_program_id' => $get('target_program_id'),
            'target_curriculum_version_id' => $get('target_curriculum_version_id'),
            'finance_adjustment' => $get('finance_adjustment') ?? 0,
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
        $data = self::lifecycleData($get);

        if (blank($data['student_profile_id']) || blank($data['term_id']) || blank($data['type']) || blank($data['effective_on'])) {
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

    private static function courseEnrollmentLabel(CourseEnrollment $courseEnrollment): string
    {
        $courseEnrollment->loadMissing('termOffering.curriculumEntry.courseSpecification.course');
        $specification = $courseEnrollment->termOffering?->curriculumEntry?->courseSpecification;

        return collect([
            $specification?->course?->code,
            $specification?->title,
            str((string) $courseEnrollment->status)->headline()->toString(),
        ])->filter()->implode(' — ');
    }
}
