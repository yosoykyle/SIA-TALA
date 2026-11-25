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
                    ->description('Save as a draft to review later, or publish directly to the public landing page and Student Hub.')
                    ->schema([
                        TextInput::make('question')
                            ->required()
                            ->maxLength(120)
                            ->helperText('120 characters maximum.')
                            ->extraInputAttributes(['data-tala-char-limit' => '120'])
                            ->columnSpanFull(),
                        Textarea::make('answer')
                            ->required()
                            ->maxLength(2000)
                            ->rows(8)
                            ->helperText('Plain text only; 2,000 characters maximum.')
                            ->extraInputAttributes(['data-tala-char-limit' => '2000'])
                            ->columnSpanFull(),
                        Select::make('category')
                            ->options(FaqEntry::categoryOptions())
                            ->required()
                            ->searchable()
                            ->helperText('Used to group questions on the public landing page and Student Hub.')
                            ->columnSpanFull(),
                        DateTimePicker::make('visible_from')->label('Visible from (Asia/Manila)')->timezone('Asia/Manila')->seconds(false)
                            ->helperText('Optional start time in Asia/Manila. Leave blank to go live as soon as you publish.'),
                        DateTimePicker::make('visible_until')->label('Visible until (Asia/Manila)')->timezone('Asia/Manila')->seconds(false)
                            ->afterOrEqual(fn (Get $get): ?string => filled($get('visible_from')) ? 'visible_from' : null)
                            ->helperText('Optional end time in Asia/Manila. Leave blank to stay live until unpublished.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
