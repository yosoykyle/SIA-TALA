<?php

namespace App\Filament\Resources\InstallmentPolicies\Schemas;

use App\Models\InstallmentPolicy;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InstallmentPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Policy Scope')
                    ->schema([
                        TextInput::make('name')
                            ->maxLength(255)
                            ->required(),
                        Select::make('program_id')
                            ->relationship('program', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('year_level')
                            ->label('Year Level')
                            ->options(InstallmentPolicy::yearLevelOptions())
                            ->placeholder('All year levels')
                            ->helperText('Leave blank only when this policy applies to every College year level.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Payment Rules')
                    ->schema([
                        TextInput::make('max_months')
                            ->label('Maximum months')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->helperText('Client-approved installment duration is configurable up to 10 months.')
                            ->default(10),
                        Select::make('due_day_rule')
                            ->label('Due date rule')
                            ->options([
                                'end_of_month' => 'End of Month',
                            ])
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('Installments are due at month end.')
                            ->default('end_of_month'),
                        TextInput::make('grace_days')
                            ->label('Grace days')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Default grace period before penalty applies.')
                            ->default(3),
                        TextInput::make('penalty_rate')
                            ->label('Penalty rate (%)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->helperText('Applied for every missed month after grace period.')
                            ->default(5.0),
                        Select::make('penalty_frequency')
                            ->label('Penalty frequency')
                            ->options([
                                'per_missed_month' => 'Every Missed Month',
                            ])
                            ->required()
                            ->selectablePlaceholder(false)
                            ->default('per_missed_month'),
                        Toggle::make('allow_partial_payments')
                            ->label('Allow partial payments')
                            ->default(false)
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Disabled by client rule: there is no partial-payment concept for grace/penalty prevention.')
                            ->required(),
                        Toggle::make('promissory_is_non_clearing')
                            ->label('Promissory is non-clearing')
                            ->default(true)
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Promissory notes do not clear finance status or unlock enrollment clearance.')
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Milestone Schedule')
                    ->description('Configure child milestone rows here. Payment status is calculated by services; this schedule only controls policy thresholds.')
                    ->schema([
                        Repeater::make('milestones')
                            ->relationship()
                            ->schema([
                                TextInput::make('sequence')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1),
                                TextInput::make('month_offset')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10),
                                TextInput::make('required_percentage')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100),
                                Toggle::make('status')
                                    ->label('Active')
                                    ->default(true)
                                    ->formatStateUsing(fn (?string $state): bool => $state !== 'inactive')
                                    ->dehydrateStateUsing(fn (bool $state): string => $state ? 'active' : 'inactive')
                                    ->required(),
                            ])
                            ->columns(4)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->collapsible(),
                    ]),
            ]);
    }
}
