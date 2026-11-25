<?php

namespace App\Filament\Resources\PublicNotices\Pages;

use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Models\PublicNotice;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ListPublicNotices extends ListRecords
{
    protected static string $resource = PublicNoticeResource::class;

    protected ?string $subheading = 'Manage announcements displayed on the public Servitech landing page.';

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        $this->getTable()->callBeforeReordering($order);
        app(ManagePublicContent::class)->reorderNotices($order, auth()->user());
        $this->getTable()->callAfterReordering($order);

        Notification::make()
            ->title('Display order updated')
            ->success()
            ->send();
    }

    public function getHeader(): ?View
    {
        $now = now();

        return view('filament.admin.public-content.summary-strip', [
            'metrics' => [
                [
                    'label' => 'Live on landing page',
                    'value' => PublicNotice::query()->effective()->count(),
                    'meta' => 'Currently visible to visitors',
                    'icon' => 'heroicon-o-megaphone',
                    'tone' => 'success',
                ],
                [
                    'label' => 'Scheduled',
                    'value' => PublicNotice::query()
                        ->where('state', 'Published')
                        ->where('visible_from', '>', $now)
                        ->where(fn (Builder $query): Builder => $query->whereNull('visible_until')->orWhere('visible_until', '>', $now))
                        ->count(),
                    'meta' => 'Queued for future window',
                    'icon' => 'heroicon-o-clock',
                    'tone' => 'default',
                ],
                [
                    'label' => 'Drafts',
                    'value' => PublicNotice::query()->where('state', 'Draft')->count(),
                    'meta' => 'Private until published',
                    'icon' => 'heroicon-o-document-text',
                    'tone' => 'warning',
                ],
                [
                    'label' => 'Total',
                    'value' => PublicNotice::query()->count(),
                    'meta' => 'All saved announcements',
                    'icon' => 'heroicon-o-rectangle-stack',
                    'tone' => 'default',
                ],
            ],
        ]);
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            PublicContent::getUrl() => 'Public Content',
            'Announcements',
        ];
    }
}
