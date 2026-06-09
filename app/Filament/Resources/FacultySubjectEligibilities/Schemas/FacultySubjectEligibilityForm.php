<?php

namespace App\Filament\Resources\FacultySubjectEligibilities\Schemas;

use App\Models\FacultySubjectEligibility;
use App\Models\Subject;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FacultySubjectEligibilityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Teaching Eligibility')
                    ->description('Academic Head, Registrar, or approved admin assigns the subjects a faculty member may teach. Faculty cannot self-assign subjects.')
                    ->schema([
                        Select::make('faculty_id')
                            ->label('Faculty')
                            ->relationship(
                                'faculty',
                                'name',
                                fn (Builder $query): Builder => $query->role(User::StaffRoleFaculty)->orderBy('name')
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Only faculty-role accounts can be selected.'),
                        Select::make('subject_id')
                            ->label('Subject')
                            ->relationship('subject', 'code')
                            ->getOptionLabelFromRecordUsing(fn (Subject $record): string => "{$record->code} - {$record->description}")
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('term_id')
                            ->label('Term scope')
                            ->relationship('term', 'term_name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Reusable across terms')
                            ->helperText('Leave blank for a reusable/global eligibility. Select a term for a term-specific override.'),
                        Select::make('status')
                            ->options(FacultySubjectEligibility::statusOptions())
                            ->required()
                            ->default(FacultySubjectEligibility::StatusActive)
                            ->in(FacultySubjectEligibility::statusValues())
                            ->helperText('Use inactive instead of deleting to preserve assignment history.'),
                        TextInput::make('priority')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(999)
                            ->helperText('Optional solver preference. Lower numbers are preferred.'),
                        TextInput::make('max_weekly_hours')
                            ->label('Max weekly hours')
                            ->numeric()
                            ->step(0.25)
                            ->minValue(0)
                            ->maxValue(99.99)
                            ->helperText('Optional cap for future automatic scheduling.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
