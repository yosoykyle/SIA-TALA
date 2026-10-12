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

    protected function getHeaderActions(): array
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead)
            ? [Action::make('returnToOversight')->label('Academic Oversight')->color('gray')
                ->url(AcademicApprovals::getUrl(['termId' => $this->oversightTermId]))]
            : [];
    }
}
