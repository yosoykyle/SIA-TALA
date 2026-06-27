<?php

namespace App\Filament\Student\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SoaView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Statement of Account';

    protected static ?string $title = 'Statement of Account';

    public function content(Schema $schema): Schema
    {
        return $schema
            ->state([
                'balance' => 'PHP 0.00',
                'term' => 'Current Active Term',
                'message' => 'No outstanding balances for the current term.',
            ])
            ->components([
                Section::make('Account Overview')
                    ->schema([
                        TextEntry::make('term')->label('Term'),
                        TextEntry::make('balance')->label('Current Balance')
                            ->badge()
                            ->color('success'),
                        TextEntry::make('message')->label('Status Note')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print SOA')
                ->icon('heroicon-o-printer')
                ->disabled(),
        ];
    }
}
