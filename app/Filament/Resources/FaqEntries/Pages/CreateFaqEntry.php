<?php

namespace App\Filament\Resources\FaqEntries\Pages;

use App\Filament\Resources\FaqEntries\FaqEntryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateFaqEntry extends CreateRecord
{
    protected static string $resource = FaqEntryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl();
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->url($this->getResourceUrl())
            ->color('gray');
    }
}
