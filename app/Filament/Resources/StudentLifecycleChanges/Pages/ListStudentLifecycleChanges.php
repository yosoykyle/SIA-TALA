<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentLifecycleChanges extends ListRecords
{
    protected static string $resource = StudentLifecycleChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
