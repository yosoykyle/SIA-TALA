<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Schemas;

use App\Models\Course;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StudentLifecycleChangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved Lifecycle Result')
                    ->schema([
                        Select::make('student_profile_id')->relationship('studentProfile', 'student_number')->searchable()->preload()->required(),
                        Select::make('term_id')->relationship('term', 'label')->searchable()->preload()->required(),
                        Select::make('type')->options(StudentLifecycleChange::typeOptions())->required()->live(),
                        Select::make('enrollment_id')->relationship('enrollment', 'id')->searchable()->preload(),
                        Select::make('course_enrollment_id')->relationship('courseEnrollment', 'id')->searchable()->preload()
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeSubjectDrop)
                            ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeSubjectDrop),
                        DatePicker::make('requested_on'),
                        DatePicker::make('effective_on')->required(),
                        DatePicker::make('decided_on')->required()->default(today()),
                        TextInput::make('authority')->required()->maxLength(255),
                        TextInput::make('private_source_reference')->maxLength(255),
                        Textarea::make('reason')->required()->maxLength(2000)->columnSpanFull(),
                    ])->columns(2),
                Section::make('Type-specific Details')
                    ->schema([
                        Select::make('expected_return_term_id')->relationship('expectedReturnTerm', 'label')->searchable()->preload()
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeLeaveOfAbsence)
                            ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeLeaveOfAbsence),
                        Select::make('target_program_id')->relationship('targetProgram', 'name')->searchable()->preload()
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                            ->live()
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
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                            ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift),
                        TextInput::make('finance_adjustment')->numeric()->default(0),
                        Repeater::make('credit_entries')
                            ->visible(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                            ->required(fn (Get $get): bool => $get('type') === StudentLifecycleChange::TypeProgramShift)
                            ->minItems(1)
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
                Section::make('Late Exception')
                    ->schema([
                        TextInput::make('late_exception_authority')->maxLength(255),
                        Textarea::make('late_exception_reason')->maxLength(2000),
                    ])->columns(2)->collapsed(),
            ]);
    }
}
