<?php

namespace App\Filament\Resources\FaqEntries\Pages;

use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Support\CreatesPublicContent;
use App\Models\FaqEntry;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFaqEntry extends CreateRecord
{
    use CreatesPublicContent;

    protected static string $resource = FaqEntryResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            PublicContent::getUrl() => 'Public Content',
            FaqEntryResource::getUrl('index') => 'FAQs',
            'Add FAQ',
        ];
    }

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
}
