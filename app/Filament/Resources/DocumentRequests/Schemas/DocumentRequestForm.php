<?php

namespace App\Filament\Resources\DocumentRequests\Schemas;

use App\Models\DocumentRequest;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document Request')
                    ->description('Use lifecycle table actions for payments, shipment, completion, and cancellation. System fields are generated automatically.')
                    ->schema([
                        Select::make('student_profile_id')
                            ->label('Student')
                            ->relationship('studentProfile', 'student_id')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('term_id')
                            ->label('Term')
                            ->relationship('term', 'term_name')
                            ->searchable()
                            ->preload(),
                        Select::make('document_type')
                            ->label('Document type')
                            ->options(DocumentRequest::documentTypeOptions())
                            ->required()
                            ->searchable(),
                        Select::make('status')
                            ->options([
                                DocumentRequest::StatusPendingDocumentFee => 'Pending Document Fee',
                                DocumentRequest::StatusProcessing => 'Processing',
                            ])
                            ->required()
                            ->default(DocumentRequest::StatusPendingDocumentFee)
                            ->helperText('Later lifecycle statuses are set by table actions.'),
                        Toggle::make('is_free_request')
                            ->label('Free request')
                            ->default(false)
                            ->required(),
                        Toggle::make('delivery_consent')
                            ->label('Delivery consent confirmed')
                            ->default(false)
                            ->required(),
                        Select::make('delivery_mode')
                            ->options([
                                DocumentRequest::DeliveryModePickup => 'Pickup',
                                DocumentRequest::DeliveryModeCourier => 'Courier',
                            ])
                            ->required()
                            ->default(DocumentRequest::DeliveryModePickup),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
