<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Models\Curriculum;
use App\Models\Room;
use App\Models\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make('Pre-Solver Section Planning')
                    ->description('Create the planned term sections before automatic scheduling. The solver only assigns faculty, day, time, and room against these section records.')
                    ->schema([
                        Select::make('term_id')
                            ->label('Term')
                            ->relationship('term', 'term_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('program_id')
                            ->label('Program')
                            ->relationship('program', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): null => $set('curriculum_id', null))
                            ->required(),
                        Select::make('curriculum_id')
                            ->label('Curriculum')
                            ->options(fn (Get $get): array => self::curriculumOptionsFor($get('program_id')))
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get): bool => blank($get('program_id')))
                            ->required()
                            ->helperText('Curriculum must match the selected program; subject demand is derived from curriculum subjects.'),
                        Select::make('year_level')
                            ->label('Year Level')
                            ->options(Section::yearLevelOptions())
                            ->searchable()
                            ->required(),
                        Select::make('curriculum_period')
                            ->label('Curriculum Period')
                            ->options(Section::curriculumPeriodOptions())
                            ->required(),
                        TextInput::make('name')
                            ->label('Section Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('modality')
                            ->options(Section::modalityOptions())
                            ->live()
                            ->required(),
                        Select::make('room')
                            ->label('Fixed Room')
                            ->options(fn (?Section $record): array => Room::selectOptions($record?->room))
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => Section::modalityRequiresRoom($get('modality')))
                            ->visible(fn (Get $get): bool => Section::modalityRequiresRoom($get('modality')))
                            ->helperText('Required for on-site or blended sections. Pick from Rooms; online and modular sections keep room blank.'),
                        TextInput::make('max_seats')
                            ->label('Max Seats')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(Section::MaxRescueSeats)
                            ->default(Section::MaxRescueSeats)
                            ->helperText('Editable rescue cap. Cannot exceed 30 or be lower than enrolled count.'),
                        TextInput::make('enrolled_count')
                            ->label('Enrolled Count')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function curriculumOptionsFor(mixed $programId): array
    {
        if (blank($programId)) {
            return [];
        }

        return Curriculum::query()
            ->where('program_id', (int) $programId)
            ->where('is_active', true)
            ->orderByDesc('activated_at')
            ->orderByDesc('effective_year')
            ->orderBy('version_name')
            ->get()
            ->mapWithKeys(fn (Curriculum $curriculum): array => [
                $curriculum->id => collect([
                    $curriculum->version_name,
                    $curriculum->effective_year,
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
