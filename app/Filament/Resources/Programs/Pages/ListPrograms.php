<?php

namespace App\Filament\Resources\Programs\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Programs\ProgramResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ListPrograms extends ListRecords
{
    protected static string $resource = ProgramResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    public function getTitle(): string
    {
        return 'Programs';
    }

    public function getSubheading(): string
    {
        return 'Select a program to prepare its study plans.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            CatalogCurriculaWorkbench::getUrl(array_filter([
                'oversightTermId' => $this->oversightTermId,
            ], fn (mixed $value): bool => $value !== null)) => 'Programs & Curricula',
            'Programs',
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
            ProgramResource::createProgramAction(),
        ];
    }
}
