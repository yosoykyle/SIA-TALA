<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Courses\CourseResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    public function getTitle(): string
    {
        return 'Subjects';
    }

    public function getSubheading(): string
    {
        return 'Subjects available to use in program curricula.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            CatalogCurriculaWorkbench::getUrl(array_filter([
                'oversightTermId' => $this->oversightTermId,
            ], fn (mixed $value): bool => $value !== null)) => 'Catalog & Curricula',
            'Subjects',
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
            CourseResource::createSubjectAction(),
        ];
    }
}
