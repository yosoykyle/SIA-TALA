<?php

namespace App\Filament\Resources\SectionMeetings\Schemas;

use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SectionMeetingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Manual Official Schedule Assignment')
                    ->description('Registrar creates official meeting rows with typed fields. Commit metadata is recorded by the system, and conflicts are validated before save.')
                    ->schema([
                        Select::make('term_id')
                            ->label('Term')
                            ->relationship('term', 'term_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('section_id')
                            ->label('Section')
                            ->relationship('section', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('subject_id')
                            ->label('Subject')
                            ->relationship('subject', 'code')
                            ->getOptionLabelFromRecordUsing(fn (Subject $record): string => "{$record->code} - {$record->description}")
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('faculty_id')
                            ->label('Faculty')
                            ->options(fn (): array => User::role('faculty')->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                        Select::make('day_of_week')
                            ->label('Day')
                            ->options(self::dayOptions())
                            ->required(),
                        TimePicker::make('starts_at')
                            ->label('Start time')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('ends_at')
                            ->label('End time')
                            ->seconds(false)
                            ->after('starts_at')
                            ->required(),
                        Select::make('modality')
                            ->options(self::modalityOptions())
                            ->live()
                            ->required(),
                        TextInput::make('room')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => in_array($get('modality'), ['on_site', 'blended'], true))
                            ->helperText('Required for on-site or blended meetings. Leave blank for online or modular meetings when no physical room is assigned.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function dayOptions(): array
    {
        return SectionMeeting::dayOptions();
    }

    /**
     * @return array<string, string>
     */
    private static function modalityOptions(): array
    {
        return SectionMeeting::modalityOptions();
    }
}
