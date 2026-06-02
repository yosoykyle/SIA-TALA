<?php

namespace App\Filament\Resources\CorVerifications\Pages;

use App\Filament\Resources\CorVerifications\CorVerificationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCorVerification extends EditRecord
{
    protected static string $resource = CorVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
