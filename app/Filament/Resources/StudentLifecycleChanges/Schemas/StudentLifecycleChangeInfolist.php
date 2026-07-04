<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Schemas;

use App\Models\StudentLifecycleChange;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StudentLifecycleChangeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lifecycle Result')->schema([
                    TextEntry::make('studentProfile.student_number')->label('Student Number'),
                    TextEntry::make('type')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                    TextEntry::make('term.label')->label('Effective Term'),
                    TextEntry::make('effective_on')->date(),
                    TextEntry::make('state')->badge(),
                    TextEntry::make('authority'),
                    TextEntry::make('reason')->columnSpanFull(),
                ])->columns(3),
                Section::make('Program Shift Assignment')->schema([
                    TextEntry::make('old_program')->label('Old / Current Program')
                        ->state(fn (StudentLifecycleChange $record): string => data_get($record->impact_snapshot, 'program_before.name') ?? data_get($record, 'studentProfile.program.name') ?? 'Not recorded'),
                    TextEntry::make('old_curriculum')->label('Old / Current Curriculum')
                        ->state(fn (StudentLifecycleChange $record): string => data_get($record->impact_snapshot, 'curriculum_version_before.name') ?? data_get($record, 'studentProfile.curriculumVersion.name') ?? 'Not recorded'),
                    TextEntry::make('targetProgram.name')->label('Target Program')->placeholder('Not recorded'),
                    TextEntry::make('targetCurriculumVersion.name')->label('Target Curriculum')->placeholder('Not recorded'),
                    TextEntry::make('state')->label('Evaluation State')->badge(),
                    TextEntry::make('impact_snapshot.finance_adjustment')->label('Recorded Fee Impact')->placeholder('0'),
                ])->columns(3)->visible(fn ($record): bool => $record?->type === StudentLifecycleChange::TypeProgramShift),
                Section::make('Immutable Impact Preview')->schema([
                    TextEntry::make('impact_snapshot')->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->columnSpanFull(),
                ]),
                Section::make('Program Shift Credit Checklist')->schema([
                    RepeatableEntry::make('programShiftCredits')->schema([
                        TextEntry::make('curriculumEntry.courseSpecification.course.code')->label('Target Course'),
                        TextEntry::make('curriculumEntry.courseSpecification.title')->label('Target Title'),
                        TextEntry::make('treatment')->badge(),
                        TextEntry::make('sourceCourse.code')->label('Source Course')->placeholder('Not required'),
                        TextEntry::make('numeric_grade'),
                        TextEntry::make('state')->badge(),
                    ])->columns(3),
                ])->visible(fn ($record): bool => $record?->type === StudentLifecycleChange::TypeProgramShift),
            ]);
    }
}
