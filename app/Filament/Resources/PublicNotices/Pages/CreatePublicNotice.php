<?php

namespace App\Filament\Resources\PublicNotices\Pages;

use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Models\PublicNotice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePublicNotice extends CreateRecord
{
    protected static string $resource = PublicNoticeResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ManagePublicContent::class)->create(PublicNotice::class, auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Notice draft saved. It is not public.';
    }
}
