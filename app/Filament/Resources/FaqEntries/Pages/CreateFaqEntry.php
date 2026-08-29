<?php

namespace App\Filament\Resources\FaqEntries\Pages;

use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Models\FaqEntry;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFaqEntry extends CreateRecord
{
    protected static string $resource = FaqEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ManagePublicContent::class)->create(FaqEntry::class, auth()->user(), $data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'FAQ draft saved. It is not public.';
    }

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
