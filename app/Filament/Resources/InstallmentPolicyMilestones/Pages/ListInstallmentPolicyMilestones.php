<?php

namespace App\Filament\Resources\InstallmentPolicyMilestones\Pages;

use App\Filament\Resources\InstallmentPolicyMilestones\InstallmentPolicyMilestoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstallmentPolicyMilestones extends ListRecords
{
    protected static string $resource = InstallmentPolicyMilestoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
