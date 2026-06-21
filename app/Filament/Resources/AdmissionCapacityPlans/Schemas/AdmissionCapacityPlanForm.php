<?php

namespace App\Filament\Resources\AdmissionCapacityPlans\Schemas;

use App\Models\AdmissionCapacityPlan;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AdmissionCapacityPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Effective Capacity Scope')
                    ->description('OR-backed payment secures capacity against every matching approved plan. Use parent and sub-plans instead of one hardcoded cap.')
                    ->schema([
                        Select::make('term_id')
                            ->relationship('term', 'term_name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('scope_type')
                            ->options(AdmissionCapacityPlan::scopeTypeOptions())
                            ->required()
                            ->live()
                            ->default(AdmissionCapacityPlan::ScopeCampus),
                        Select::make('program_id')
                            ->relationship('program', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => in_array($get('scope_type'), [
                                AdmissionCapacityPlan::ScopeProgram,
                                AdmissionCapacityPlan::ScopeYearLevel,
                                AdmissionCapacityPlan::ScopeDeliverySetup,
                            ], true)),
                        Select::make('year_level')
                            ->label('Year Level')
                            ->options([
                                '1st Year' => '1st Year',
                                '2nd Year' => '2nd Year',
                                '3rd Year' => '3rd Year',
                                '4th Year' => '4th Year',
                            ])
                            ->searchable()
                            ->visible(fn (Get $get): bool => in_array($get('scope_type'), [
                                AdmissionCapacityPlan::ScopeYearLevel,
                                AdmissionCapacityPlan::ScopeDeliverySetup,
                            ], true)),
                        Select::make('delivery_setup')
                            ->options([
                                'on_site' => 'On-site',
                                'online' => 'Online',
                                'blended' => 'Blended',
                            ])
                            ->visible(fn (Get $get): bool => $get('scope_type') === AdmissionCapacityPlan::ScopeDeliverySetup),
                        TextInput::make('capacity_limit')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->default(100),
                        TextInput::make('reserved_count')
                            ->integer()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Updated only by OR-backed finance clearance.'),
                        Select::make('status')
                            ->options(AdmissionCapacityPlan::statusOptions())
                            ->required()
                            ->live()
                            ->default(AdmissionCapacityPlan::StatusDraft),
                        DateTimePicker::make('effective_from')
                            ->seconds(false),
                        DateTimePicker::make('effective_until')
                            ->seconds(false),
                        Select::make('approved_by')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('status') === AdmissionCapacityPlan::StatusApproved),
                        DateTimePicker::make('approved_at')
                            ->seconds(false)
                            ->required(fn (Get $get): bool => $get('status') === AdmissionCapacityPlan::StatusApproved),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
