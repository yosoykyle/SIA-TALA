<?php

namespace App\Filament\Resources\AdmissionOfferings\Schemas;

use App\Models\AdmissionOffering;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AdmissionOfferingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Published Offering Scope')
                    ->description('Controls which applicant scopes are available for a term. Unsupported routes stay draft or retired.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('term_id')
                            ->relationship('term', 'term_name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('program_id')
                            ->relationship('program', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Leave blank only for level-wide offerings.'),
                        Select::make('education_level')
                            ->options(AdmissionOffering::educationLevelOptions())
                            ->required()
                            ->live(),
                        Select::make('entry_route')
                            ->options(AdmissionOffering::entryRouteOptions())
                            ->required(),
                        Select::make('prior_credential_pathway')
                            ->options(AdmissionOffering::priorCredentialOptions())
                            ->placeholder('Regular')
                            ->helperText('Use ALS only for the approved Regular SHS Grade 11 pathway.'),
                        TextInput::make('citizenship_compliance_profile')
                            ->maxLength(255)
                            ->helperText('Leave blank for MVP local applicants; foreign compliance profiles stay inactive until approved.'),
                        Select::make('year_level')
                            ->label('Year/Grade')
                            ->options(fn (Get $get): array => self::yearLevelOptions((string) $get('education_level')))
                            ->searchable(),
                        Select::make('status')
                            ->options(AdmissionOffering::statusOptions())
                            ->required()
                            ->live()
                            ->default(AdmissionOffering::StatusDraft),
                        DateTimePicker::make('published_at')
                            ->seconds(false)
                            ->required(fn (Get $get): bool => $get('status') === AdmissionOffering::StatusPublished)
                            ->helperText('Required before this offering can resolve an applicant checklist.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function yearLevelOptions(string $educationLevel): array
    {
        return match ($educationLevel) {
            'shs' => [
                'Grade 11' => 'Grade 11',
                'Grade 12' => 'Grade 12',
            ],
            'college' => [
                '1st Year' => '1st Year',
                '2nd Year' => '2nd Year',
                '3rd Year' => '3rd Year',
                '4th Year' => '4th Year',
            ],
            default => [],
        };
    }
}
