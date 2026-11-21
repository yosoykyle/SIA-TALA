<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListGradeRosters extends ListRecords
{
    use HasAcademicOversightReturn;

    protected static string $resource = GradeRosterResource::class;

    #[Url]
    public string $viewTab = 'grade-review';

    public function mount(): void
    {
        parent::mount();

        if ($this->isRegistrarActor()) {
            $this->redirect(GradesAndCompletion::getUrl([
                'viewTab' => 'grade-review',
                ...GradesAndCompletion::classQueueParameters(request()->query()),
            ]));
        }
    }

    public function getTitle(): string
    {
        if ($this->isAcademicHeadReader()) {
            return 'Class rosters';
        }

        return 'Class grades';
    }

    public function getSubheading(): ?string
    {
        if ($this->isAcademicHeadReader()) {
            return 'Find a class, check official membership and inspect released results. Use the filters to select a term.';
        }

        return parent::getSubheading();
    }

    public function getBreadcrumbs(): array
    {
        if ($this->isAcademicHeadReader()) {
            return [
                $this->oversightReturnUrl() => 'Academic Oversight',
                'Class rosters',
            ];
        }

        return [GradesAndCompletion::getUrl() => 'Grades & Completion', 'Class grades'];
    }

    public function headRosterUrl(GradeRoster $roster): string
    {
        return GradeRosterResource::getUrl('view', array_filter([
            'record' => $roster,
            'oversightTermId' => $this->resolvedOversightTermId(),
            'returnFilters' => ($this->tableFilters ?? []) !== [] ? $this->tableFilters : null,
            'returnSearch' => filled($this->getTableSearch()) ? $this->getTableSearch() : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->isAcademicHeadReader() ? ['class' => 'tala-head-rosters-page'] : parent::getExtraBodyAttributes();
    }

    protected function getHeaderActions(): array
    {
        return $this->isAcademicHeadReader()
            ? [$this->returnToOversightAction()]
            : [];
    }
}
