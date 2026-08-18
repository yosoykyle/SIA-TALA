<?php

namespace App\Filament\Resources\AdmissionCycles\Schemas;

use App\Models\AdmissionCycle;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AdmissionCycleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cycle authority')
                    ->schema([
                        TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true),
                        TextInput::make('label')->required()->maxLength(160),
                        Select::make('term_id')
                            ->label('Target term')
                            ->relationship('term', 'label')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('registrar_owner_id')
                            ->label('Registrar owner')
                            ->relationship('registrarOwner', 'email')
                            ->searchable()
                            ->preload()
                            ->required(),
                        DateTimePicker::make('opens_at')->label('Opening')->native(false)->required(),
                        DateTimePicker::make('closes_at')->label('Public closing')->native(false)->after('opens_at')->required(),
                        DateTimePicker::make('correction_closes_at')
                            ->label('New-correction closing')
                            ->helperText('Optional while Draft. Publication readiness requires this at or after public closing.')
                            ->native(false),
                        Textarea::make('applicant_instructions')->required()->maxLength(2000)->columnSpanFull(),
                        TextInput::make('support_contact')->required()->maxLength(255),
                        TextInput::make('privacy_notice_reference')->required()->maxLength(255),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Programs and paths')
                    ->description('The selected paths apply to every selected Program in this bounded cycle version.')
                    ->schema([
                        CheckboxList::make('accepted_paths')
                            ->label('Accepted paths')
                            ->options([
                                AdmissionCycle::PathFirstYear => 'First year',
                                AdmissionCycle::PathTransferee => 'Transferee',
                            ])
                            ->default([
                                AdmissionCycle::PathFirstYear,
                                AdmissionCycle::PathTransferee,
                            ])
                            ->required()
                            ->dehydrated(false),
                        Select::make('programs')
                            ->label('Accepting Programs')
                            ->relationship('programs', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->saveRelationshipsUsing(function (AdmissionCycle $record, array $state, Get $get): void {
                                $paths = (array) $get('accepted_paths');
                                $record->programs()->sync(collect($state)->mapWithKeys(fn (int|string $programId): array => [
                                    (int) $programId => [
                                        'accepts_first_year' => in_array(AdmissionCycle::PathFirstYear, $paths, true),
                                        'accepts_transferee' => in_array(AdmissionCycle::PathTransferee, $paths, true),
                                    ],
                                ])->all());
                            }),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
