<?php

namespace App\Filament\Resources\Programs\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Resources\Programs\ProgramResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Url;

class ViewProgram extends ViewRecord
{
    protected static string $resource = ProgramResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('returnToOversight')->label('Academic Oversight')->color('gray')
                ->url(AcademicApprovals::getUrl(['termId' => $this->oversightTermId]))
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false),
            EditAction::make(),
        ];
    }
}
