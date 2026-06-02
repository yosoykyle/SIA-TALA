<?php

namespace App\Filament\Resources\FeeTemplates\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FeeTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('education_level')
                    ->options([
                        'shs' => 'SHS',
                        'college' => 'College',
                    ])
                    ->required(),
                Select::make('program_id')
                    ->relationship('program', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Leave blank only if this template applies to all programs for the selected education level.'),
                TextInput::make('year_level')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(12)
                    ->helperText('Use 11/12 for SHS and 1-4 for College.'),
                TextInput::make('tuition_fee')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0.0),
                TextInput::make('laboratory_fee')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0.0),
                TextInput::make('misc_fee')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0.0),
                TextInput::make('other_fee')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0.0),
                TextInput::make('minimum_downpayment_percentage')
                    ->label('Minimum downpayment percentage')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->helperText('Used for MVP finance clearance. Full installments are handled by Installment Policies.')
                    ->default(20.0),
                Toggle::make('is_active')
                    ->label('Active')
                    ->required(),
            ]);
    }
}
