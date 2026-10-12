<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListStudentProfiles extends ListRecords
{
    protected static string $resource = StudentProfileResource::class;

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
