<?php

namespace App\Filament\Resources\SystemSettings\Schemas;

use App\Models\SystemSetting;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SystemSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Documented Setting')
                    ->description('Seeded settings only. Create and delete are disabled to prevent undocumented runtime keys.')
                    ->schema([
                        Hidden::make('setting_key')
                            ->dehydrated(false),
                        Hidden::make('value_type')
                            ->dehydrated(false),
                        Hidden::make('is_editable')
                            ->dehydrated(false),
                        TextInput::make('setting_label')
                            ->label('Setting')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('category_label')
                            ->label('Category')
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('setting_description')
                            ->label('Purpose')
                            ->rows(3)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Toggle::make('boolean_value')
                            ->label('Enabled')
                            ->helperText(fn (Get $get): string => SystemSetting::helperFor($get('setting_key')))
                            ->visible(fn (Get $get): bool => $get('value_type') === SystemSetting::ValueTypeBoolean),
                        DateTimePicker::make('datetime_value')
                            ->label('Date and time')
                            ->native(false)
                            ->seconds(false)
                            ->helperText(fn (Get $get): string => SystemSetting::helperFor($get('setting_key')))
                            ->visible(fn (Get $get): bool => $get('value_type') === SystemSetting::ValueTypeDatetime)
                            ->columnSpanFull(),
                        Textarea::make('text_value')
                            ->label('Text value')
                            ->rows(5)
                            ->maxLength(2000)
                            ->helperText(fn (Get $get): string => SystemSetting::helperFor($get('setting_key')))
                            ->visible(fn (Get $get): bool => $get('value_type') === SystemSetting::ValueTypeText)
                            ->columnSpanFull(),
                        Textarea::make('json_value')
                            ->label('JSON value')
                            ->rows(12)
                            ->rules(['required', 'json'])
                            ->helperText(fn (Get $get): string => SystemSetting::helperFor($get('setting_key')))
                            ->visible(fn (Get $get): bool => $get('value_type') === SystemSetting::ValueTypeJson)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
