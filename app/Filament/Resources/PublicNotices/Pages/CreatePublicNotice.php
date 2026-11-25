<?php

namespace App\Filament\Resources\PublicNotices\Pages;

use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Filament\Support\CreatesPublicContent;
use App\Models\PublicNotice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePublicNotice extends CreateRecord
{
    use CreatesPublicContent;

    protected static string $resource = PublicNoticeResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            PublicContent::getUrl() => 'Public Content',
            PublicNoticeResource::getUrl('index') => 'Announcements',
            'Add announcement',
        ];
    }

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
        return 'Announcement draft saved. It is not public.';
    }
}
