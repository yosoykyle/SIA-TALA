<?php

namespace App\Filament\Resources\FaqEntries\Pages;

use App\Actions\PublicContent\ManagePublicContent;
use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Models\FaqEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListFaqEntries extends ListRecords
{
    protected static string $resource = FaqEntryResource::class;

    protected ?string $subheading = 'Manage frequently asked questions shown on the public landing page and Student Hub.';

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        $this->getTable()->callBeforeReordering($order);
        app(ManagePublicContent::class)->reorderFaqs($order, auth()->user());
        $this->getTable()->callAfterReordering($order);

        Notification::make()
            ->title('Display order updated')
            ->success()
            ->send();
    }

    public function getHeader(): ?View
    {
        return view('filament.admin.public-content.summary-strip', [
            'metrics' => [
                [
                    'label' => 'Live on landing page',
                    'value' => FaqEntry::query()->effective()->count(),
                    'meta' => 'Published to visitors & students',
                    'icon' => 'heroicon-o-check-circle',
                    'tone' => 'success',
                ],
                [
                    'label' => 'Categories',
                    'value' => FaqEntry::query()->whereNotNull('category')->distinct()->count('category'),
                    'meta' => 'Active FAQ topic groups',
                    'icon' => 'heroicon-o-tag',
                    'tone' => 'default',
                ],
                [
                    'label' => 'Drafts',
                    'value' => FaqEntry::query()->where('is_published', false)->where('ever_published', false)->count(),
                    'meta' => 'Private until published',
                    'icon' => 'heroicon-o-document-text',
                    'tone' => 'warning',
                ],
                [
                    'label' => 'Total',
                    'value' => FaqEntry::query()->count(),
                    'meta' => 'All saved FAQ entries',
                    'icon' => 'heroicon-o-question-mark-circle',
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
            'FAQs',
        ];
    }
}
