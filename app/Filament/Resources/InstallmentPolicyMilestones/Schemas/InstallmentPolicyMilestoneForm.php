<?php

namespace App\Filament\Resources\InstallmentPolicyMilestones\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InstallmentPolicyMilestoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('installment_policy_id')
                    ->relationship('installmentPolicy', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
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
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->required()
                    ->default('active'),
            ]);
    }
}
