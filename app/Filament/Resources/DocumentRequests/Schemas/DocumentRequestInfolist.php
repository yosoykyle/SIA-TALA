<?php

namespace App\Filament\Resources\DocumentRequests\Schemas;

use App\Models\DocumentRequest;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DocumentRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('studentProfile.student_id')
                    ->label('Student ID'),
                TextEntry::make('studentProfile.user.name')
                    ->label('Student'),
                TextEntry::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('document_type')
                    ->formatStateUsing(fn (?string $state): string => DocumentRequest::documentTypeLabel($state)),
                TextEntry::make('status'),
                IconEntry::make('is_free_request')
                    ->boolean(),
                IconEntry::make('delivery_consent')
                    ->boolean(),
                TextEntry::make('delivery_mode'),
                TextEntry::make('courier_name')
                    ->placeholder('-'),
                TextEntry::make('tracking_number')
                    ->placeholder('-'),
                TextEntry::make('tracking_number_normalized')
                    ->placeholder('-'),
                TextEntry::make('shipping_fee')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('courier_receipt_path')
                    ->label('Courier receipt proof')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Uploaded' : 'Not uploaded')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray'),
                TextEntry::make('shipped_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('shipping_grace_ends_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('shipping_fee_assessment_transaction_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('shipping_fee_payment_transaction_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('creator.name')
                    ->label('Created by')
                    ->placeholder('-'),
                TextEntry::make('updater.name')
                    ->label('Updated by')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
