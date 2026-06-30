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
                Section::make('Immutable Impact Preview')->schema([
                    TextEntry::make('impact_snapshot')->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->columnSpanFull(),
                ]),
                Section::make('Program Shift Credit Checklist')->schema([
                    RepeatableEntry::make('programShiftCredits')->schema([
                        TextEntry::make('curriculumEntry.courseSpecification.course.code')->label('Target Course'),
                        TextEntry::make('treatment')->badge(),
                        TextEntry::make('numeric_grade'),
                    ])->columns(3),
                ])->visible(fn ($record): bool => $record?->type === StudentLifecycleChange::TypeProgramShift),
            ]);
    }
}
