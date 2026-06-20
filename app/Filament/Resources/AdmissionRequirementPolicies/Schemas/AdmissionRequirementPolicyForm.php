<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Schemas;

use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AdmissionRequirementPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Versioned Requirement Policy')
                    ->description('A policy is active only when approved and effective for its offering. Checklist rows snapshot this policy version.')
                    ->schema([
                        Select::make('admission_offering_id')
                            ->label('Admission Offering')
                            ->options(fn (): array => AdmissionOffering::query()
                                ->with(['term', 'program'])
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (AdmissionOffering $offering): array => [$offering->id => $offering->displayLabel()])
                                ->all())
                            ->required()
                            ->searchable(),
                        TextInput::make('version')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->default(1),
                        TextInput::make('source_label')
                            ->maxLength(255)
                            ->helperText('Optional source or approval note, for example Regular SHS 2026.'),
                        Select::make('status')
                            ->options(AdmissionRequirementPolicy::statusOptions())
                            ->required()
                            ->live()
                            ->default(AdmissionRequirementPolicy::StatusDraft),
                        DateTimePicker::make('effective_from')
                            ->seconds(false)
                            ->helperText('Leave blank only for immediate/manual activation.'),
                        DateTimePicker::make('effective_until')
                            ->seconds(false),
                        Select::make('approved_by')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('status') === AdmissionRequirementPolicy::StatusActive),
                        DateTimePicker::make('approved_at')
                            ->seconds(false)
                            ->required(fn (Get $get): bool => $get('status') === AdmissionRequirementPolicy::StatusActive),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
