<?php

namespace App\Filament\Resources\FaqEntries\Pages;

use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Support\EditsPublicContent;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditFaqEntry extends EditRecord
{
    use EditsPublicContent;

    protected static string $resource = FaqEntryResource::class;

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->url($this->getResourceUrl())
            ->color('gray');
    }
}
