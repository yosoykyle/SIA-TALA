<?php

namespace App\Filament\Resources\GradeRosters\Pages;

use App\Filament\Pages\AcademicApprovals;
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

    public function getTitle(): string
    {
        return $this->isHeadReader() ? 'Class rosters' : parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        return $this->isHeadReader() ? 'Find a class, check official membership and inspect released results. Use the filters to select a term.' : parent::getSubheading();
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
