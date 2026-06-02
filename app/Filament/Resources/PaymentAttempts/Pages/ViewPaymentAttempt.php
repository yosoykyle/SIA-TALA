<?php

namespace App\Filament\Resources\PaymentAttempts\Pages;

use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentAttempt extends ViewRecord
{
    protected static string $resource = PaymentAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
