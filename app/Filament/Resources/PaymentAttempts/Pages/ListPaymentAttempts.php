<?php

namespace App\Filament\Resources\PaymentAttempts\Pages;

use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentAttempts extends ListRecords
{
    protected static string $resource = PaymentAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
