<?php

namespace App\Filament\Resources\PublicNotices\Schemas;

use App\Models\PublicNotice;
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
            Section::make('Notice draft')
                ->description('System Administration owns this public guidance. Saving published content creates a successor draft; the public version stays unchanged until you publish.')
                ->schema([
                    TextInput::make('title')->required()->maxLength(160)->columnSpanFull(),
                    Textarea::make('message')->required()->maxLength(500)->rows(4)->helperText('Plain text only; 500 characters maximum.')->columnSpanFull(),
                    TextInput::make('display_order')->label('Display position')->integer()->required()->minValue(1)
                        ->default(fn (): int => ((int) PublicNotice::query()->max('display_order')) + 1)
                        ->helperText('The next position is suggested from saved notices. You can change it; overlapping published windows must use different positions.'),
                    DateTimePicker::make('visible_from')->label('Visible from (Asia/Manila)')->timezone('Asia/Manila')->seconds(false),
                    DateTimePicker::make('visible_until')->label('Visible until (Asia/Manila)')->timezone('Asia/Manila')->seconds(false)
                        ->afterOrEqual(fn (Get $get): ?string => filled($get('visible_from')) ? 'visible_from' : null),
                    TextInput::make('link_label')->label('Link label (optional)')->maxLength(80)->requiredWith('link_url'),
                    TextInput::make('link_url')->label('HTTPS link (optional)')->rules(['nullable', 'url:https'])->maxLength(2048)->requiredWith('link_label'),
                ])->columns(2)->columnSpanFull(),
        ]);
    }
}
