<?php

namespace App\Filament\Resources\FaqEntries\Schemas;

use App\Models\FaqEntry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FaqEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('FAQ Content')
                    ->description('FAQ entries are curated by System Super Admin. Author and timestamps are recorded automatically.')
                    ->schema([
                        TextInput::make('question')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('answer')
                            ->required()
                            ->rows(8)
                            ->columnSpanFull(),
                        Select::make('category')
                            ->options(FaqEntry::categoryOptions())
                            ->required()
                            ->default(FaqEntry::CategoryGeneral)
                            ->searchable()
                            ->helperText('Fixed category list approved for public and Student Hub FAQ filtering.'),
                        TextInput::make('sort_order')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Lower numbers appear first.'),
                        Toggle::make('is_published')
                            ->label('Published')
                            ->required()
                            ->helperText('Unpublished entries stay hidden from public/student FAQ views.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
