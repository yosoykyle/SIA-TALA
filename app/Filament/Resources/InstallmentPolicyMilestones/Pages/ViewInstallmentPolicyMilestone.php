<?php

namespace App\Filament\Resources\InstallmentPolicyMilestones\Pages;

use App\Filament\Resources\InstallmentPolicyMilestones\InstallmentPolicyMilestoneResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInstallmentPolicyMilestone extends ViewRecord
{
    protected static string $resource = InstallmentPolicyMilestoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
