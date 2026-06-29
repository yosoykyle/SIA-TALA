<?php

namespace App\Filament\Resources\FeeRules\Schemas;

use App\Models\FeeRule;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FeeRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Fee Rule')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('code')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('ledger_category')
                                    ->options(FeeRule::ledgerCategoryOptions())
                                    ->required()
                                    ->native(false),
                                Select::make('display_category')
                                    ->options(FeeRule::displayCategoryOptions())
                                    ->required()
                                    ->live()
                                    ->native(false),
                                Select::make('program_id')
                                    ->relationship('program', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->placeholder('All programs')
                                    ->required(fn (Get $get): bool => $get('display_category') === FeeRule::DisplayCategoryDownpayment)
                                    ->helperText('Required for a downpayment rule.'),
                                Select::make('term_id')
                                    ->relationship('term', 'label')
                                    ->searchable()
                                    ->preload()
                                    ->placeholder('All terms')
                                    ->required(fn (Get $get): bool => $get('display_category') === FeeRule::DisplayCategoryDownpayment)
                                    ->helperText('Required for a downpayment rule.'),
                                Select::make('calculation_type')
                                    ->options(FeeRule::calculationTypeOptions())
                                    ->required()
                                    ->live()
                                    ->native(false),
                                TextInput::make('amount')
                                    ->label('Amount (PHP)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(9999999999.99)
                                    ->step(0.01)
                                    ->prefix('₱')
                                    ->rules(['decimal:0,2'])
                                    ->visible(fn (Get $get): bool => in_array($get('calculation_type'), [FeeRule::CalculationFixed, FeeRule::CalculationManual], true))
                                    ->required(fn (Get $get): bool => in_array($get('calculation_type'), [FeeRule::CalculationFixed, FeeRule::CalculationManual], true)),
                                TextInput::make('rate')
                                    ->label('Per-unit rate (PHP)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(9999999999.99)
                                    ->step(0.01)
                                    ->prefix('₱')
                                    ->rules(['decimal:0,2'])
                                    ->helperText('Peso amount charged for each enrolled unit.')
                                    ->visible(fn (Get $get): bool => $get('calculation_type') === FeeRule::CalculationPerUnit)
                                    ->required(fn (Get $get): bool => $get('calculation_type') === FeeRule::CalculationPerUnit),
                                DatePicker::make('effective_from')
                                    ->required(),
                                DatePicker::make('effective_until'),
                                Toggle::make('is_active')
                                    ->default(true)
                                    ->required(),
                                TextInput::make('authority')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}
