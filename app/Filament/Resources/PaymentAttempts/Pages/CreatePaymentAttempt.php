<?php

namespace App\Filament\Resources\PaymentAttempts\Pages;

use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentAttempt extends CreateRecord
{
    protected static string $resource = PaymentAttemptResource::class;
}
