<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Filament\Resources\StudentProfiles\Pages\EditStudentProfile;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Filament\Resources\StudentProfiles\RelationManagers\HoldsRelationManager;
use App\Models\StudentProfile;
use App\Models\Term;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
                TextInput::make('student_number')->disabled()->dehydrated(),
                TextInput::make('first_name')->required()->maxLength(255),
                TextInput::make('middle_name')->maxLength(255),
                TextInput::make('last_name')->required()->maxLength(255),
                Select::make('program_id')->relationship('program', 'name')->required()->searchable()->preload(),
                Select::make('curriculum_version_id')->relationship('curriculumVersion', 'name')->required()->searchable()->preload(),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('phone')->maxLength(255),
            ])->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Current Official State')->schema([
                TextEntry::make('student_number')->label('Student Number'),
                TextEntry::make('full_name')->label('Student Name')->state(fn (StudentProfile $record): string => collect([$record->first_name, $record->middle_name, $record->last_name])->filter()->implode(' ')),
                TextEntry::make('program.name')->label('Program'),
                TextEntry::make('curriculumVersion.name')->label('Curriculum Version'),
                TextEntry::make('lifecycle_status')->badge(),
                TextEntry::make('academic_standing')->badge(),
            ])->columns(3),
            Section::make('Progression Facts')->schema([
                TextEntry::make('recommended_standing')->state(fn (StudentProfile $record): string => app(AcademicProgressionService::class)->evaluate($record)['standing'])->badge(),
                TextEntry::make('progression_blockers')->state(fn (StudentProfile $record): int => count(app(AcademicProgressionService::class)->evaluate($record)['blockers']))->numeric(),
                TextEntry::make('back_subject_count')->state(fn (StudentProfile $record): int => count(app(AcademicProgressionService::class)->evaluate($record)['back_subjects']))->numeric(),
                TextEntry::make('gwa')->label('GWA')->state(fn (StudentProfile $record): ?string => app(AcademicProgressionService::class)->evaluate($record)['gwa'])->placeholder('Not available'),
                RepeatableEntry::make('subject_suggestions')
                    ->state(fn (StudentProfile $record): array => app(AcademicProgressionService::class)->evaluate($record, Term::query()->where('state', Term::StateActive)->first())['suggestions'])
                    ->schema([
                        TextEntry::make('course_code')->label('Course'),
                        TextEntry::make('title'),
                        TextEntry::make('units'),
                        TextEntry::make('offering_category')->badge(),
                    ])->columns(4)->columnSpanFull(),
            ])->columns(3),
            Section::make('Lifecycle History')->schema([
                RepeatableEntry::make('lifecycleChanges')->schema([
                    TextEntry::make('type')->badge(),
                    TextEntry::make('term.label')->label('Term'),
                    TextEntry::make('effective_on')->date(),
                    TextEntry::make('state')->badge(),
                    TextEntry::make('reason'),
                ])->columns(5),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('student_number')->searchable()->sortable(),
            TextColumn::make('last_name')->label('Student')->formatStateUsing(fn (StudentProfile $record): string => $record->last_name.', '.$record->first_name)->searchable(['last_name', 'first_name'])->sortable(),
            TextColumn::make('program.name')->searchable()->sortable(),
            TextColumn::make('curriculumVersion.name')->label('Curriculum')->sortable(),
            TextColumn::make('lifecycle_status')->badge()->sortable(),
            TextColumn::make('academic_standing')->badge()->sortable(),
        ])->filters([
            SelectFilter::make('lifecycle_status')->options([
                StudentProfile::LifecycleActive => 'Active',
                StudentProfile::LifecycleLeaveOfAbsence => 'Leave of Absence',
                StudentProfile::LifecycleWithdrawn => 'Withdrawn',
                StudentProfile::LifecycleTransferredOut => 'Transferred Out',
                StudentProfile::LifecycleInactive => 'Inactive',
                StudentProfile::LifecycleArchived => 'Archived',
            ]),
            SelectFilter::make('academic_standing')->options(array_combine(AcademicProgressionService::standingValues(), AcademicProgressionService::standingValues())),
        ])->recordActions([ViewAction::make(), EditAction::make()])->defaultSort('student_number');
    }

    public static function getRelations(): array
    {
        return [ChecklistItemsRelationManager::class, HoldsRelationManager::class];
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
