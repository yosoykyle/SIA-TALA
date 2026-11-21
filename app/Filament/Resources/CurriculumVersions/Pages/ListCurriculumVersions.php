<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ListCurriculumVersions extends ListRecords
{
    protected static string $resource = CurriculumVersionResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    public function getTitle(): string
    {
        return 'Curricula';
    }

    public function getSubheading(): string
    {
        return 'Study plans grouped by program and student intake.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            CatalogCurriculaWorkbench::getUrl(array_filter([
                'oversightTermId' => $this->oversightTermId,
            ], fn (mixed $value): bool => $value !== null)) => 'Programs & Curricula',
            'Curricula',
        ];
    }

    protected function getHeaderActions(): array
    {
        $oversightParameters = array_filter([
            'termId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            Action::make('returnToOversight')
                ->label('Return to Academic Oversight')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(AcademicApprovals::getUrl($oversightParameters))
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false),
            CurriculumVersionResource::createDraftAction(oversightTermId: $this->oversightTermId),
        ];
    }
}
