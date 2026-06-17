<?php

namespace App\Filament\Resources\DeliveryPatterns\Schemas;

use App\Models\DeliveryPattern;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeliveryPatternForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Versioned Delivery Pattern')
                    ->description('Reusable delivery rules. Once a pattern is used, clone a new version instead of mutating the historical version.')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('code')
                                    ->required()
                                    ->maxLength(50)
                                    ->helperText('Stable pattern code, for example COL-ONLINE-MINOR.'),
                                TextInput::make('version')
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1),
                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true),
                            ]),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->maxLength(2000)
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('modality')
                            ->options(DeliveryPattern::modalityOptions())
                            ->searchable()
                            ->helperText('Optional. Leave blank only for a generic pattern that can be used by any delivery group.'),
                        Select::make('subject_routing')
                            ->options(DeliveryPattern::subjectRoutingOptions())
                            ->required()
                            ->default(DeliveryPattern::SubjectRoutingSameSubjectSet),
                        Select::make('enforcement_level')
                            ->options(DeliveryPattern::enforcementLevelOptions())
                            ->required()
                            ->default(DeliveryPattern::EnforcementStrict),
                        CheckboxList::make('allowed_days')
                            ->options(DeliveryPattern::dayOptions())
                            ->columns(4)
                            ->bulkToggleable()
                            ->helperText('Optional operating-day hint for the scheduler. Exact hard enforcement lands in the scheduling solver slice.')
                            ->columnSpanFull(),
                        Toggle::make('default_room_required')
                            ->label('Requires room by default')
                            ->helperText('Allowed only for on-site or blended patterns.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
