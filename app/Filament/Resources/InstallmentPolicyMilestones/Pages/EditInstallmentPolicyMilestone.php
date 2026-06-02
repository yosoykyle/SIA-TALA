<?php

namespace App\Filament\Resources\InstallmentPolicyMilestones\Pages;

use App\Filament\Resources\InstallmentPolicyMilestones\InstallmentPolicyMilestoneResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInstallmentPolicyMilestone extends EditRecord
{
    protected static string $resource = InstallmentPolicyMilestoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
