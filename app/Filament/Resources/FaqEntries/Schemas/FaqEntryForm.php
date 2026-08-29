<?php

namespace App\Filament\Resources\FaqEntries\Schemas;

use App\Models\FaqEntry;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FaqEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('FAQ Content')
                    ->description('System Administration owns FAQ guidance. Saving published content creates a successor draft. Publish separately after reviewing its window and position.')
                    ->schema([
                        TextInput::make('question')
                            ->required()
                            ->maxLength(160)
                            ->columnSpanFull(),
                        Textarea::make('answer')
                            ->required()
                            ->maxLength(3000)
                            ->rows(8)
                            ->columnSpanFull(),
                        Select::make('category')
                            ->options(FaqEntry::categoryOptions())
                            ->required()
                            ->searchable()
                            ->helperText('Fixed category list approved for public and Student Hub FAQ filtering.'),
                        TextInput::make('sort_order')->label('Display position')->integer()->required()->minValue(1)
                            ->default(fn (): int => ((int) FaqEntry::query()->max('sort_order')) + 1)
                            ->helperText('The next saved position is suggested. Change it to reorder; publication checks overlapping positions within the category.'),
                        DateTimePicker::make('visible_from')->label('Visible from (Asia/Manila)')->timezone('Asia/Manila')->seconds(false),
                        DateTimePicker::make('visible_until')->label('Visible until (Asia/Manila)')->timezone('Asia/Manila')->seconds(false)
                            ->afterOrEqual(fn (Get $get): ?string => filled($get('visible_from')) ? 'visible_from' : null),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
