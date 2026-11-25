<?php

namespace App\Filament\Resources\PublicNotices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PublicNoticeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Announcement draft')
                ->description('Save as a draft to review later, or publish directly to the public landing page. Leave start and end times blank to publish immediately until unpublished.')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(120)
                        ->helperText('120 characters maximum.')
                        ->extraInputAttributes(['data-tala-char-limit' => '120'])
                        ->columnSpanFull(),
                    Textarea::make('message')
                        ->required()
                        ->maxLength(500)
                        ->rows(4)
                        ->helperText('Plain text only; 500 characters maximum.')
                        ->extraInputAttributes(['data-tala-char-limit' => '500'])
                        ->columnSpanFull(),
                    DateTimePicker::make('visible_from')->label('Visible from (Asia/Manila)')->timezone('Asia/Manila')->seconds(false)
                        ->helperText('Optional start time in Asia/Manila. Leave blank to go live as soon as you publish.'),
                    DateTimePicker::make('visible_until')->label('Visible until (Asia/Manila)')->timezone('Asia/Manila')->seconds(false)
                        ->afterOrEqual(fn (Get $get): ?string => filled($get('visible_from')) ? 'visible_from' : null)
                        ->helperText('Optional end time in Asia/Manila. Leave blank to stay live until unpublished.'),
                    TextInput::make('link_url')
                        ->label('HTTPS link (optional)')
                        ->rules(['nullable', 'url:https'])
                        ->maxLength(2048)
                        ->live(onBlur: true)
                        ->helperText('Optional HTTPS link (e.g. https://...).')
                        ->columnSpanFull(),
                    TextInput::make('link_label')
                        ->label('Link label')
                        ->maxLength(60)
                        ->visible(fn (Get $get): bool => filled($get('link_url')))
                        ->required(fn (Get $get): bool => filled($get('link_url')))
                        ->helperText('60 characters maximum.')
                        ->extraInputAttributes(['data-tala-char-limit' => '60'])
                        ->columnSpanFull(),
                ])->columns(2)->columnSpanFull(),
        ]);
    }
}
