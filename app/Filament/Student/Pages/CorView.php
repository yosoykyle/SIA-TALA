<?php

namespace App\Filament\Student\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CorView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'COR';

    protected static ?string $title = 'Certificate of Registration';

    public function content(Schema $schema): Schema
    {
        return $schema
            ->state([
                'status' => 'Pending Enrollment',
                'term' => 'Current Active Term',
                'message' => 'No active COR is available for download at this time. Please ensure you are officially enrolled.',
            ])
            ->components([
                Section::make('Current Term Status')
                    ->schema([
                        TextEntry::make('term')->label('Term'),
                        TextEntry::make('status')->label('Enrollment Status')
                            ->badge()
                            ->color('warning'),
                        TextEntry::make('message')->label('Notice')
                            ->color('danger')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print COR')
                ->icon('heroicon-o-printer')
                ->disabled(),
        ];
    }
}
