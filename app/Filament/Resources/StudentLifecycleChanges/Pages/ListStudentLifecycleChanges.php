<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListStudentLifecycleChanges extends ListRecords
{
    protected static string $resource = StudentLifecycleChangeResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [Action::make('returnToOversight')->label('Academic Oversight')->color('gray')
                ->url(AcademicApprovals::getUrl(['termId' => $this->oversightTermId]))];
        }

        return [
            CreateAction::make(),
        ];
    }
}
