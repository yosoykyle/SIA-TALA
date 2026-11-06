<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListGradeRosters extends ListRecords
{
    protected static string $resource = GradeRosterResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    #[Url]
    public string $viewTab = 'grade-review';

    public function mount(): void
    {
        parent::mount();

        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            $this->redirect(GradesAndCompletion::getUrl([
                'viewTab' => 'grade-review',
                ...GradesAndCompletion::classQueueParameters(request()->query()),
            ]));
        }
    }

    public function getTitle(): string
    {
        if ($this->isHeadReader()) {
            return 'Class rosters';
        }

        return 'Class grades';
    }

    public function getSubheading(): ?string
    {
        if ($this->isHeadReader()) {
            return 'Find a class, check official membership and inspect released results. Use the filters to select a term.';
        }

        return parent::getSubheading();
    }

    public function getBreadcrumbs(): array
    {
        if (! $this->isHeadReader()) {
            return [GradesAndCompletion::getUrl() => 'Grades & Completion', 'Class grades'];
        }

        return parent::getBreadcrumbs();
    }

    private function isHeadReader(): bool
    {
        return (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false)
            && ! auth()->user()->hasRole(User::StaffRoleRegistrar);
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->isHeadReader() ? ['class' => 'tala-head-rosters-page'] : parent::getExtraBodyAttributes();
    }

    protected function getHeaderActions(): array
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead)
            ? [Action::make('returnToOversight')->label('Academic Oversight')->color('gray')
                ->url(AcademicApprovals::getUrl(['termId' => $this->oversightTermId]))]
            : [];
    }
}
